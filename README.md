# VM Hotswap

Unraid plugin for hot-swapping disk image files on VMs.

## What it does

Adds a page to the Unraid web UI that lets you:

- **Attach a new disk** to a VM (running or stopped) from a defined images directory
- **Detach a disk** from a VM
- **Swap the backing file** of an existing disk:
  - Running VM, CDROM/floppy → `virsh change-media` (hot media eject/insert, safe)
  - VM shut off, any disk → rewrite the domain XML (safe, atomic on next boot)

Hot-swapping a running VM's regular hard disk is deliberately not supported — pulling the OS disk out from under a live guest crashes it. Shut the VM down first for those.

## Status

Early development. Do not use on production VMs yet.

## Install (once released)

Add the following plugin URL under **Plugins → Install Plugin**:

```
https://raw.githubusercontent.com/<user>/vm-hotswap/main/vm-hotswap.plg
```

For now, clone this repo and use `scripts/build-package.sh` to build a `.txz` and install it manually.

## Requirements

- Unraid 6.11+ (libvirt available)
- VMs backed by `.img` or `.qcow2` files
- The source images directory must be readable by the `root` user and accessible to libvirt/qemu

## Configuration

Under **Settings → VM Hotswap** (once installed):

- **Source images path** — where the plugin looks for disk images available to attach. Defaults to `/mnt/user/isos/vm-disks/`. Anything ending in `.img`, `.qcow2`, `.raw`, or `.vhd` is listed.

## Safety

- The plugin never deletes source images.
- Before a cold swap it writes the current domain XML to `/boot/config/plugins/vm-hotswap/backup/<domain>-<timestamp>.xml`.
- Hot detach on a running VM asks libvirt to eject cleanly. If the VM has the disk mounted, the OS inside the VM must release it first — the plugin will surface an error rather than force.

## Development

- `source/vm-hotswap/` — files packaged into the `.txz` and installed under `/usr/local/emhttp/plugins/vm-hotswap/`
- `scripts/build-package.sh` — builds a versioned `.txz` in `archive/`
- `vm-hotswap.plg` — Unraid plugin manifest; points at the `.txz` URL and carries embedded install/uninstall scripts

## License

MIT. See LICENSE.
