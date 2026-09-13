# -*- coding: utf-8 -*-
"""Upload a single file to any path relative to the FTP root (/www/wwwroot).

Usage: ftp_put_any.py <local_file> <remote_rel_path>
Example: ftp_put_any.py E:/dsh/x/index.html i18n.yibianhui.cn/index.html
"""
import os
import sys
from ftplib import FTP

HOST, USER, PWD = '47.238.193.61', 'DSHroot', 'X4YHEHZfNJaf'

local, remote_rel = sys.argv[1], sys.argv[2].lstrip('/')

ftp = FTP(HOST, timeout=90)
ftp.login(USER, PWD)
ftp.set_pasv(True)
ftp.voidcmd('TYPE I')

# ensure parent dirs exist
parts = remote_rel.split('/')[:-1]
cur = ''
for p in parts:
    cur = (cur + '/' + p) if cur else p
    try:
        ftp.cwd(cur)
    except Exception:
        ftp.mkd(cur)
        ftp.cwd(cur)
ftp.cwd('/')

with open(local, 'rb') as f:
    ftp.storbinary('STOR ' + remote_rel, f)
size = ftp.size(remote_rel)
print('uploaded %s -> /%s (local %d B, remote %s B)' % (
    local, remote_rel, os.path.getsize(local), size))
ftp.quit()
