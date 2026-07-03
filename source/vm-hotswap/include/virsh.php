<?PHP
/*
 * vm-hotswap virsh + qemu-img wrappers.
 *
 * All operations funnel through virsh (libvirt CLI) or qemu-img so we
 * get libvirt-side locking + XML consistency for free instead of poking
 * the QMP socket ourselves. Every function that can fail returns
 * [ok, output_or_error]; callers stringify to JSON for the web UI.
 */

// Escape a single shell argument. escapeshellarg wraps in single quotes
// and escapes inner single quotes — safe for virsh names + file paths.
function vh_arg($s) { return escapeshellarg((string)$s); }

// Run a command, return [exit_code, combined_output_string].
function vh_exec($cmd) {
    $out = [];
    $rc = 0;
    exec($cmd . ' 2>&1', $out, $rc);
    return [$rc, implode("\n", $out)];
}

// Return the plugin's settings.cfg values (sourced Slackware-style).
function vh_settings() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cfg_path = '/boot/config/plugins/vm-hotswap/settings.cfg';
    $cache = ['IMAGES_PATH' => '/mnt/user/isos/vm-disks/', 'BACKUP_XML' => '1'];
    if (!file_exists($cfg_path)) return $cache;
    foreach (file($cfg_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (preg_match('/^([A-Z_]+)="?([^"]*)"?$/', trim($line), $m)) {
            $cache[$m[1]] = $m[2];
        }
    }
    return $cache;
}

// List every VM libvirt knows about, running or stopped.
function vh_list_domains() {
    [$rc, $out] = vh_exec('virsh list --all --name');
    if ($rc !== 0) return [false, "virsh list failed: $out"];
    $names = array_values(array_filter(array_map('trim', explode("\n", $out))));
    $domains = [];
    foreach ($names as $name) {
        [$rc, $state] = vh_exec('virsh domstate ' . vh_arg($name));
        $domains[] = [
            'name'  => $name,
            'state' => $rc === 0 ? trim($state) : 'unknown',
        ];
    }
    return [true, $domains];
}

// List disks attached to a domain. Uses --details for target/source/type.
function vh_list_disks($domain) {
    $cmd = 'virsh domblklist ' . vh_arg($domain) . ' --details';
    [$rc, $out] = vh_exec($cmd);
    if ($rc !== 0) return [false, "domblklist failed: $out"];
    $lines = array_values(array_filter(array_map('trim', explode("\n", $out))));
    // Skip the two-line header ("Type ... Target ..." + separator dashes).
    $disks = [];
    foreach ($lines as $line) {
        if (preg_match('/^(file|block)\s+(disk|cdrom|floppy)\s+(\S+)\s+(.*)$/', $line, $m)) {
            $disks[] = [
                'type'   => $m[1],     // file | block
                'device' => $m[2],     // disk | cdrom | floppy
                'target' => $m[3],     // vda, sda, hdc, etc.
                'source' => $m[4],     // path on host filesystem (may be "-" for empty cdrom)
            ];
        }
    }
    return [true, $disks];
}

// List candidate images under the configured directory, non-recursive.
function vh_list_images() {
    $cfg = vh_settings();
    $dir = rtrim($cfg['IMAGES_PATH'], '/') . '/';
    if (!is_dir($dir)) return [false, "images path not found: $dir"];
    $exts = ['img', 'qcow2', 'raw', 'vhd', 'vhdx', 'vmdk'];
    $images = [];
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $full = $dir . $f;
        if (!is_file($full)) continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (!in_array($ext, $exts)) continue;
        $size = filesize($full);
        $images[] = [
            'name'   => $f,
            'path'   => $full,
            'size'   => $size,
            'ext'    => $ext,
        ];
    }
    return [true, $images];
}

// Query qemu-img for info about an image file (format, virtual size, backing).
function vh_image_info($path) {
    if (!file_exists($path)) return [false, "image not found: $path"];
    [$rc, $out] = vh_exec('qemu-img info --output=json ' . vh_arg($path));
    if ($rc !== 0) return [false, "qemu-img info failed: $out"];
    $data = json_decode($out, true);
    if ($data === null) return [false, "qemu-img info returned non-JSON: $out"];
    return [true, $data];
}

// Back up the domain XML to /boot/config/plugins/vm-hotswap/backup/.
function vh_backup_xml($domain) {
    $cfg = vh_settings();
    if (empty($cfg['BACKUP_XML']) || $cfg['BACKUP_XML'] === '0') return [true, null];
    $dir = '/boot/config/plugins/vm-hotswap/backup';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $ts = date('Ymd-His');
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $domain);
    $path = "$dir/{$safe}-{$ts}.xml";
    [$rc, $out] = vh_exec('virsh dumpxml ' . vh_arg($domain) . ' > ' . vh_arg($path));
    if ($rc !== 0) return [false, "backup dumpxml failed: $out"];
    return [true, $path];
}

// Attach a disk. When live=true and the domain is running, includes
// --live --persistent so it survives a reboot.
function vh_attach_disk($domain, $source, $target, $driver = 'qcow2', $bus = 'virtio', $live = true) {
    if (!file_exists($source)) return [false, "source not found: $source"];
    $flags = '--driver qemu --subdriver ' . vh_arg($driver) . ' --targetbus ' . vh_arg($bus);
    // --config always so the change persists in the XML. Add --live only
    // for running domains — libvirt errors if you use --live on a shut off.
    [$rc, $state] = vh_exec('virsh domstate ' . vh_arg($domain));
    $running = $rc === 0 && trim($state) === 'running';
    $persistence = $running && $live ? '--live --config' : '--config';
    vh_backup_xml($domain);
    $cmd = "virsh attach-disk " . vh_arg($domain) . ' ' . vh_arg($source) . ' ' . vh_arg($target)
         . " $flags $persistence";
    [$rc, $out] = vh_exec($cmd);
    if ($rc !== 0) return [false, "attach-disk failed: $out"];
    return [true, "attached $source as $target"];
}

// Detach a disk by target (e.g. "vdb"). Behaves like attach re: live/config.
function vh_detach_disk($domain, $target, $live = true) {
    [$rc, $state] = vh_exec('virsh domstate ' . vh_arg($domain));
    $running = $rc === 0 && trim($state) === 'running';
    $persistence = $running && $live ? '--live --config' : '--config';
    vh_backup_xml($domain);
    $cmd = 'virsh detach-disk ' . vh_arg($domain) . ' ' . vh_arg($target) . " $persistence";
    [$rc, $out] = vh_exec($cmd);
    if ($rc !== 0) return [false, "detach-disk failed: $out"];
    return [true, "detached $target"];
}

// ── Hot swap ─────────────────────────────────────────────────────────────
//
// Scope: swap the ISO / image in a CDROM (or floppy) drive on a running
// VM. `virsh change-media` handles this cleanly — the emulated drive
// gets an eject + insert atomically, guest sees new media appear, no
// filesystem or driver dance required.
//
// Regular hard disks are handled by the cold-swap path only (VM shut
// off, XML rewrite). Hot-plugging a running OS disk would crash the
// guest, so we don't expose it.

// Change the media in a running CDROM/floppy drive. Atomic eject + insert.
function vh_change_media($domain, $target, $new_source) {
    if (!file_exists($new_source)) return [false, "new source not found: $new_source"];
    vh_backup_xml($domain);
    // --update replaces current media; --live applies to running domain;
    // --config persists in XML so it survives a reboot.
    $cmd = 'virsh change-media ' . vh_arg($domain) . ' ' . vh_arg($target)
         . ' ' . vh_arg($new_source) . ' --update --live --config';
    [$rc, $out] = vh_exec($cmd);
    if ($rc !== 0) return [false, "change-media failed: $out"];
    return [true, "media swapped on $target → $new_source"];
}

// Swap the backing file of an existing disk. Cold path only for v0 —
// requires the domain to be stopped so we can rewrite the XML safely.
// See vh_swap_disk_hot for the running-VM path.
function vh_swap_disk_cold($domain, $target, $new_source) {
    [$rc, $state] = vh_exec('virsh domstate ' . vh_arg($domain));
    if (trim($state) !== 'shut off') {
        return [false, "cold swap requires domain to be shut off (currently: " . trim($state) . ")"];
    }
    if (!file_exists($new_source)) return [false, "new source not found: $new_source"];

    // Dump the current XML, edit the matching <disk> block's source path,
    // define the new XML. `virsh define` replaces the domain definition.
    vh_backup_xml($domain);
    [$rc, $xml] = vh_exec('virsh dumpxml ' . vh_arg($domain));
    if ($rc !== 0) return [false, "dumpxml failed: $xml"];

    // Find the disk with matching <target dev="X"/> and rewrite its source.
    // Simple regex — libvirt formats XML predictably enough that this
    // survives across releases. If a domain has ridiculous XML we'll add
    // an XML-parser fallback in v0.1.
    $target_esc = preg_quote($target, '/');
    $pattern = '/(<disk[^>]*>[\s\S]*?<target\s+dev=[\'"]' . $target_esc . '[\'"][^>]*\/>[\s\S]*?<\/disk>)/i';
    if (!preg_match($pattern, $xml, $m)) {
        return [false, "no disk block found for target=$target"];
    }
    $block = $m[1];
    // Rewrite the <source ... /> line inside that disk block.
    $new_block = preg_replace(
        '/<source\s+file=[\'"][^\'"]*[\'"]\s*\/>/',
        '<source file=' . htmlspecialchars(json_encode($new_source), ENT_QUOTES) . '/>',
        $block,
        1
    );
    if ($new_block === $block) {
        return [false, "no <source file=\"...\"/> line found in disk block for $target"];
    }
    $new_xml = str_replace($block, $new_block, $xml);
    $tmp = tempnam(sys_get_temp_dir(), 'vmhotswap-');
    file_put_contents($tmp, $new_xml);
    [$rc, $out] = vh_exec('virsh define ' . vh_arg($tmp));
    unlink($tmp);
    if ($rc !== 0) return [false, "virsh define failed: $out"];
    return [true, "cold-swapped $target → $new_source"];
}
