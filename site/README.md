# open-admin 静态官网

Copyright (c) 2026 erik, erik@erik.xyz, https://erik.xyz

`site/` 是项目的独立静态官网。纯 HTML、CSS、JavaScript，无服务端渲染、无数据库依赖，包含独立的 404 页面与图片点击放大。

## 本地预览

```bash
python3 -m http.server 8800 --directory site
```

打开 `http://localhost:8800/`。

## 部署

- 将 `site/` 作为静态站点根目录，或放在现有站点的 `/site/` 路径下。
- `site/` 位于项目根目录，不由 webman 的 `public/` 目录托管；开发时请使用下方静态服务器，生产环境由 Nginx 直接返回。
- 主站页面使用相对路径；404 页面使用 `/site/` 绝对路径，独立部署到站点根目录时需把 `404.html` 中的 `/site/` 改为 `/`。
- 除 Busuanzi 阅读统计脚本外，three.js、图片、样式与脚本全部位于本站点内。
- 项目自带的 `docs/nginx-security.conf` 已将 `/site/` 直接交给 Nginx 返回，并设置静态缓存与 404 回退。

## 缓存策略

为了让静态响应稳定落在 10ms 内，已在 Nginx 参考配置中启用：

- `/site/assets/*`：`expires 1y`
- `/site/*.html`：`expires 5m`
- gzip 覆盖 HTML、CSS、JS、JSON、XML 与 SVG。

站点自身没有服务端逻辑，响应时间取决于静态服务器、磁盘缓存和网络链路。

## 目录

```text
site/
├── index.html
├── 404.html
├── README.md
└── assets/
    ├── css/site.css
    ├── js/site.js
    ├── js/hero.js
    ├── img/pet.svg
    ├── img/pet-expressions/
    ├── img/diagrams/
    └── vendor/three.module.min.js
```

## 第三方组件

- three.js r170，MIT License，许可证见 `assets/vendor/LICENSE-three.txt`。
- Busuanzi 阅读统计由 `busuanzi.ibruce.info` 异步提供。

视觉资产（项目宠物、图片、图标、表情）适用仓库根目录的 `ASSETS-LICENSE.md`。
