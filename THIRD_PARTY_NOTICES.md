# Third-party notices

## QR Code Generator for JavaScript

`public/qr-local.js` is vendored from `qrcode-generator` version 1.4.4.
Copyright (c) 2009 Kazuhiko Arase. Licensed under the MIT License.
The original copyright and attribution header is retained in the source.

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.

## Development dependency

PHP source parsing uses `php-parser` from the npm registry. Its license and
attribution are included in the installed package; dependencies are not vendored.

## Remotely loaded assets

The interface references Google Fonts and the SheetJS spreadsheet library
using URLs retained in the application source. Those assets are not bundled
in this repository.
