# Third-party notices

The Weixin HTTP protocol integration in `WeixinNotifyHelper.php` and encrypted
image reception in `BotMomentsMediaHelper.php` follow Tencent's
`@tencent-weixin/openclaw-weixin` version 2.4.10:

- Source: https://github.com/Tencent/openclaw-weixin
- Package: https://www.npmjs.com/package/@tencent-weixin/openclaw-weixin/v/2.4.10
- Protocol reference: https://github.com/Tencent/openclaw-weixin/blob/main/docs/protocol_zh_CN.md

This Typecho PHP adaptation implements QR authentication, inbound recipient
discovery, text delivery, and CDN image download/decryption. Authentication and
text delivery follow the PHP implementation in SBlog's `comment-push` plugin;
image reception follows the upstream Weixin media protocol using PHP's cURL and
OpenSSL extensions. It does not include the OpenClaw runtime or its AI agent.
The upstream copyright and license are reproduced below.

---

Tencent is pleased to support the open source community by making openclaw-weixin available.

Copyright (C) 2026 Tencent.  All rights reserved.

openclaw-weixin is licensed under the MIT.

Terms of the MIT:

Permission is hereby granted, free of charge, to any person obtaining
a copy of this software and associated documentation files (the
"Software"), to deal in the Software without restriction, including
without limitation the rights to use, copy, modify, merge, publish,
distribute, sublicense, and/or sell copies of the Software, and to
permit persons to whom the Software is furnished to do so, subject to
the following conditions:

The above copyright notice and this permission notice shall be
included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY
CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT,
TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE
SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

---

The local QR-code renderer uses `qrcode-generator` version 2.0.4:

- Copyright (c) 2009 Kazuhiko Arase
- License: MIT; the complete upstream license is retained in
  `assets/vendor/qrcode.LICENSE`.
- Source: https://github.com/kazuhikoarase/qrcode-generator
- Package: https://www.npmjs.com/package/qrcode-generator
- Vendored files: `assets/vendor/qrcode.js` and
  `assets/vendor/qrcode_UTF8.js`.

QR rendering runs locally in the administrator's browser. No third-party QR
rendering service receives the Weixin login payload.
