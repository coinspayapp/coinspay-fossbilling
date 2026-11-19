"""Builds the two archives of a release in dist/:

- coinspay-fossbilling-<version>.zip: the module and the payment adapter at the paths they take in
  the root of FOSSBilling, so the archive is unpacked there as it is;
- coinspay-fossbilling-module-<version>.zip: the module alone, its files at the root of the
  archive, as the extension directory of FOSSBilling takes it. The module carries the adapter in
  Gateway/ and writes it into library/Payment/Adapter/Coinspay when activated.

    python bin/build.py   # prints the paths of both archives, one per line
"""
import json
import os
import re
import sys
import zipfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
MODULE = os.path.join(ROOT, 'modules', 'Coinspay')
GATEWAY = os.path.join(MODULE, 'Gateway')


def version():
    with open(os.path.join(MODULE, 'Lib', 'Settings.php'), encoding='utf-8') as f:
        found = re.search(r"VERSION = '([^']+)'", f.read())
    with open(os.path.join(MODULE, 'manifest.json'), encoding='utf-8') as f:
        manifest = json.load(f)
    if not found or found.group(1) != manifest.get('version'):
        sys.exit('Settings::VERSION and the version of manifest.json differ')
    return found.group(1)


def write_tree(archive, source, prefix):
    for folder, dirs, files in os.walk(source):
        dirs.sort()
        for name in sorted(files):
            path = os.path.join(folder, name)
            archive.write(path, prefix + os.path.relpath(path, source).replace(os.sep, '/'))


def main():
    out_dir = os.path.join(ROOT, 'dist')
    os.makedirs(out_dir, exist_ok=True)
    v = version()

    full = os.path.join(out_dir, 'coinspay-fossbilling-%s.zip' % v)
    with zipfile.ZipFile(full, 'w', zipfile.ZIP_DEFLATED) as archive:
        write_tree(archive, MODULE, 'modules/Coinspay/')
        archive.write(os.path.join(ROOT, 'LICENSE'), 'modules/Coinspay/LICENSE')
        # The same files the module writes on activation: an unpacked archive works at once.
        write_tree(archive, GATEWAY, 'library/Payment/Adapter/Coinspay/')

    module = os.path.join(out_dir, 'coinspay-fossbilling-module-%s.zip' % v)
    with zipfile.ZipFile(module, 'w', zipfile.ZIP_DEFLATED) as archive:
        write_tree(archive, MODULE, '')
        archive.write(os.path.join(ROOT, 'LICENSE'), 'LICENSE')

    print(full)
    print(module)


if __name__ == '__main__':
    main()
