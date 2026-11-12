"""Builds dist/coinspay-fossbilling-<version>.zip: the module and the payment adapter at the paths
they take in the root of FOSSBilling, so the archive is unpacked there as it is.

    python bin/build.py
"""
import json
import os
import re
import sys
import zipfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PARTS = [
    ('modules/Coinspay', os.path.join(ROOT, 'modules', 'Coinspay')),
    ('library/Payment/Adapter/Coinspay', os.path.join(ROOT, 'library', 'Payment', 'Adapter', 'Coinspay')),
]


def version():
    with open(os.path.join(ROOT, 'modules', 'Coinspay', 'Lib', 'Settings.php'), encoding='utf-8') as f:
        found = re.search(r"VERSION = '([^']+)'", f.read())
    with open(os.path.join(ROOT, 'modules', 'Coinspay', 'manifest.json'), encoding='utf-8') as f:
        manifest = json.load(f)
    if not found or found.group(1) != manifest.get('version'):
        sys.exit('Settings::VERSION and the version of manifest.json differ')
    return found.group(1)


def main():
    out_dir = os.path.join(ROOT, 'dist')
    os.makedirs(out_dir, exist_ok=True)
    target = os.path.join(out_dir, 'coinspay-fossbilling-%s.zip' % version())
    with zipfile.ZipFile(target, 'w', zipfile.ZIP_DEFLATED) as archive:
        for prefix, source in PARTS:
            for folder, dirs, files in os.walk(source):
                dirs.sort()
                for name in sorted(files):
                    path = os.path.join(folder, name)
                    archive.write(path, prefix + '/' + os.path.relpath(path, source).replace(os.sep, '/'))
        archive.write(os.path.join(ROOT, 'LICENSE'), 'modules/Coinspay/LICENSE')
    print(target)


if __name__ == '__main__':
    main()
