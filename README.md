# ImageHelper

Help with loading and resizing images (including ICO icons) from strings and
returning image resources for use with the gd library.

Supports alpha transparency and upscaling

Other useful methods to follow.

## Usage

```
$data = file_get_contents('image.file');
$imageHelper = new ImageHelper($data);
$resized = $imageHelper->resize(256, 256, true);
imagepng($resized);
```
