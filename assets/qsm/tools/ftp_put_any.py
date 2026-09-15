# -*- coding: utf-8 -*-
"""Upload a single file to any path relative to the FTP root (/www/wwwroot).

Usage:
    set YBH_FTP_HOST=...  YBH_FTP_USER=...  YBH_FTP_PWD=...
    ftp_put_any.py <local_file> <remote_rel_path>

Example:
    ftp_put_any.py E:/dsh/x/index.html i18n.yibianhui.cn/index.html

⚠️ 凭据**只从环境变量读**，绝不写进这个文件。
    本文件曾在 3cdd73e9 里硬编码过 FTP 账号密码并被推送到了公开仓库，
    属于一次真实的凭据泄露事件 —— 那些密码必须视为已失效并轮换。
"""
import os
import sys
from ftplib import FTP

HOST = os.environ.get('YBH_FTP_HOST', '')
USER = os.environ.get('YBH_FTP_USER', '')
PWD = os.environ.get('YBH_FTP_PWD', '')

if not (HOST and USER and PWD):
    sys.exit('请先设置环境变量 YBH_FTP_HOST / YBH_FTP_USER / YBH_FTP_PWD')

if len(sys.argv) < 3:
    sys.exit('用法: ftp_put_any.py <本地文件> <相对 FTP 根的路径>')

local, remote_rel = sys.argv[1], sys.argv[2].lstrip('/')

ftp = FTP(HOST, timeout=90)
ftp.login(USER, PWD)
ftp.set_pasv(True)
ftp.voidcmd('TYPE I')

# ensure parent dirs exist（每步都用绝对路径；累积相对路径在 cwd 之后会失效）
parts = [p for p in remote_rel.split('/')[:-1] if p]
cur = ''
for p in parts:
    cur = cur + '/' + p
    try:
        ftp.cwd(cur)
    except Exception:
        ftp.mkd(cur)
        ftp.cwd(cur)

with open(local, 'rb') as f:
    ftp.storbinary('STOR ' + remote_rel, f)
size = ftp.size(remote_rel)
print('uploaded %s -> /%s (local %d B, remote %s B)' % (
    local, remote_rel, os.path.getsize(local), size))
ftp.quit()
