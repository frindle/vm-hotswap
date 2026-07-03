<?PHP
/*
 * JSON API entry point. Called by the web UI via fetch('scripts/api.php').
 * All handlers echo JSON and exit. Auth is handled by Unraid — this file
 * only loads under /usr/local/emhttp which requires a logged-in session.
 */

require_once __DIR__ . '/../include/virsh.php';

function j($data) { header('Content-Type: application/json'); echo json_encode($data); exit; }

$action = $_REQUEST['action'] ?? '';

switch ($action) {
    case 'settings':
        j(['ok' => true, 'settings' => vh_settings()]);
        break;

    case 'domains':
        [$ok, $data] = vh_list_domains();
        j($ok ? ['ok' => true, 'domains' => $data] : ['ok' => false, 'error' => $data]);
        break;

    case 'disks':
        $domain = $_REQUEST['domain'] ?? '';
        if ($domain === '') j(['ok' => false, 'error' => 'domain required']);
        [$ok, $data] = vh_list_disks($domain);
        j($ok ? ['ok' => true, 'disks' => $data] : ['ok' => false, 'error' => $data]);
        break;

    case 'images':
        [$ok, $data] = vh_list_images();
        j($ok ? ['ok' => true, 'images' => $data] : ['ok' => false, 'error' => $data]);
        break;

    case 'image_info':
        $path = $_REQUEST['path'] ?? '';
        [$ok, $data] = vh_image_info($path);
        j($ok ? ['ok' => true, 'info' => $data] : ['ok' => false, 'error' => $data]);
        break;

    case 'attach':
        $domain = $_POST['domain'] ?? '';
        $source = $_POST['source'] ?? '';
        $target = $_POST['target'] ?? '';
        $driver = $_POST['driver'] ?? 'qcow2';
        $bus    = $_POST['bus'] ?? 'virtio';
        [$ok, $msg] = vh_attach_disk($domain, $source, $target, $driver, $bus);
        j(['ok' => $ok, 'message' => $msg]);
        break;

    case 'detach':
        $domain = $_POST['domain'] ?? '';
        $target = $_POST['target'] ?? '';
        [$ok, $msg] = vh_detach_disk($domain, $target);
        j(['ok' => $ok, 'message' => $msg]);
        break;

    case 'swap_cold':
        $domain = $_POST['domain'] ?? '';
        $target = $_POST['target'] ?? '';
        $new_source = $_POST['new_source'] ?? '';
        [$ok, $msg] = vh_swap_disk_cold($domain, $target, $new_source);
        j(['ok' => $ok, 'message' => $msg]);
        break;

    case 'save_settings':
        $path = $_POST['IMAGES_PATH'] ?? '';
        $backup = $_POST['BACKUP_XML'] ?? '1';
        // Basic sanity: images path must exist + be a directory.
        if ($path !== '' && !is_dir($path)) {
            j(['ok' => false, 'error' => "images path not a directory: $path"]);
        }
        $cfg = "IMAGES_PATH=\"$path\"\nBACKUP_XML=\"$backup\"\n";
        $ok = file_put_contents('/boot/config/plugins/vm-hotswap/settings.cfg', $cfg) !== false;
        j(['ok' => $ok, 'error' => $ok ? null : 'write failed']);
        break;

    default:
        j(['ok' => false, 'error' => "unknown action: $action"]);
}
