# CommonMark PHAR Build

This directory contains the build script and configuration for creating the `commonmark.phar` package used by Phuppi.

## Purpose

The build script is included to allow recompilation of the PHAR archive when needed, such as:
- Updating to a new version of league/commonmark
- Adding new features or extensions
- Making any modifications to the PHAR configuration

## Recompiling the PHAR

To recompile the `commonmark.phar` yourself:

```bash
cd /path/to/phuppi/src/commonmark/build
chmod +x build.sh
./build.sh
```

The build script will:
1. Clone the official league/commonmark repository
2. Install dependencies via Composer
3. Build the PHAR archive using Box

## License

The [league/commonmark](https://github.com/thephpleague/commonmark) library is licensed under the **BSD 3-Clause License**. A copy of this license is included in this directory as [`LICENSE`](LICENSE).

For more information about the league/commonmark library, visit:
- GitHub: https://github.com/thephpleague/commonmark
- Documentation: https://commonmark.thephpleague.com/