**简体中文** | [繁體中文](README_tw.md) | [English](README_en.md) | [日本語](README_ja.md)

<h1 align="left">Theme SakurairoYBH</h1>

> **SakurairoYBH 是 [Sakurairo](https://github.com/mirai-mamori/Sakurairo) 的二次开发分支（fork）**，
> 由 [Yibianhui](https://www.yibianhui.cn/) 维护，用于 https://www.yibianhui.cn 。
> 上游 Sakurairo 是一款具有 AI 辅助阅读功能的 WordPress 主题，多彩、友好、功能全面、体验完善；
> 本项目在保留其全部能力的前提下，针对中文排版、字体加载性能与后台易用性做了增补。

[![GitHub release](https://img.shields.io/github/v/release/yiwubianjihui/SakurairoYBH.svg?style=for-the-badge)](https://github.com/yiwubianjihui/SakurairoYBH/releases)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg?style=for-the-badge)](https://www.gnu.org/licenses/gpl-2.0.html)

---

## 📌 本项目来源（必要声明）

本主题**不是** Sakurairo 官方版本，而是其衍生作品。完整的血脉是：

```
Sakura V3 Series  (mashirozx/sakura, 3.x)
        └── Sakurairo          (mirai-mamori/Sakurairo，作者 Fuukei)
                └── SakurairoYBH   ← 本仓库（yiwubianjihui/SakurairoYBH）
```

- **上游项目**：<https://github.com/mirai-mamori/Sakurairo>
- **上游作者**：Fuukei（mirai-mamori）
- **更上游**：[Sakura V3 Series](https://github.com/mashirozx/sakura/tree/3.x)
- **本分支维护者**：Yibianhui
- **许可证**：沿用上游 [GPL V2.0](https://github.com/mirai-mamori/Sakurairo/blob/master/LICENSE)

> 如果你在寻找官方版本、官方文档或官方支持，请前往上游仓库。
> 本仓库只对本分支自身的改动负责，**请不要把本分支的问题提交到上游**。

---

## ✨ 相对上游的改动

| 方向 | 内容 |
| --- | --- |
| **字体系统** | 默认更纱黑体（Sarasa UI SC/TC/HC/J/K），按 `:lang()` 自动匹配地区版本；天珩字库作 CJK 兜底；Klee One 用于标题签名；**霞鹜文楷作中文斜体**（通过为 `Sarasa UI SC` 补 CJK 限定的 italic 面实现，西文仍走 Sarasa 真斜体） |
| **加载性能** | 中文字体**两层子集化**（站点用字主体 + GB2312 一级字补丁，靠 `unicode-range` 按需下载），首页字体流量 21 MB → 0.84 MB；收敛全局 `transition`；懒加载与 preload 清单对齐 |
| **可读性** | 标题与签名的多背景保护层；深/浅背景下的对比度修正；导航链接 visited 锁色 |
| **布局** | 展台（Bento）密度与防遮挡；文章列表两列、禁摘要；**紧凑模式**（右下角控制台可切换，一屏显示更多文章） |
| **标签** | 首页标签行（30 个可见 + 折叠展开）；文章标签胶囊缩小 |
| **合规** | 内置 Cookie 同意横幅（替代 WPConsent），可扩展的 `data-ybh-consent` 脚本门控 |
| **设备适配** | 低端设备动效降级（按 `deviceMemory` / `hardwareConcurrency` / `Save-Data` / 2G 探测，可手动覆盖） |
| **后台** | 经典编辑器写作体验重做；「YBH 魔改」设置分区；友链批量导入 |
| **附件** | 上传图片自动转 WebP |

---

## 👥 贡献者

- **Yibianhui** —— 本分支维护者
- **EquinoxXawa** —— Modern UI 视觉层（圆角遮罩 `mask-image` 消除缩放锯齿、设计令牌体系）；其 `EquinoxX-ver` 分支中的该部分实现已被本分支采纳
- **Fuukei / mirai-mamori** 及所有 [上游贡献者](https://github.com/mirai-mamori/Sakurairo/graphs/contributors) —— Sakurairo 原始实现

---

## 📦 使用

本分支为自用维护，**未发布到 WordPress 官方主题目录**。

- 仓库：<https://github.com/yiwubianjihui/SakurairoYBH>
- 线上示例：<https://www.yibianhui.cn>
- 下载：<https://github.com/yiwubianjihui/SakurairoYBH/releases>

> ⚠️ 注意：本分支在核心中短路了上游的「主题目录名检查」逻辑
> （上游会强制目录名为 `Sakurairo` 并删除同名目录）。若你直接使用本分支，
> 主题目录名可以是 `SakurairoYBH`，不会触发改名或删目录行为。

---

## 🙏 上游信息（保留）

### 开源相关

- Sakurairo **基于 [Sakura V3 Series](https://github.com/mashirozx/sakura/tree/3.x) 主题进行重构开发**。
- Sakurairo 使用了部分来自互联网的特效。由于版权及开源协议不明，无法具体说明相关信息。

### 引用相关

- 社交网络图标中，流畅设计图标引用于由 Paradox 设计的 Fluent 图标包
- 社交网络图标中，沐氢图标引用于由缄默设计的沐氢图标包

### 依赖相关

- [Codestar Framework](https://github.com/Codestar/codestar-framework)（上游定制版 Sakurairo_CSF）作为设置框架
- [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) 提供主题更新功能
- [Kirki](https://github.com/themeum/kirki) 提供可视化编辑器相关功能

### 第三方字体

- **Sarasa Gothic / 更纱黑体**（SIL OFL 1.1）
- **LXGW WenKai / 霞鹜文楷**（SIL OFL 1.1）
- **Klee One**（SIL OFL 1.1）
- **TH-Tshyn / 天珩全字库**（按其自身授权条款使用）
- **Font Awesome**（CC BY 4.0 / SIL OFL 1.1）

---

## 希望你喜欢！

- 上游 Star 趋势 [![GitHub stars](https://img.shields.io/github/stars/mirai-mamori/Sakurairo?logo=github&style=social)](https://github.com/mirai-mamori/Sakurairo/stargazers)
