# CLI Configuration Guide for Phuppi

Welcome! This guide will help you understand and configure the command-line interface (CLI) tools that come with Phuppi. Don't worry if you're not familiar with command lines – we'll explain everything step by step.

## Table of Contents

1. [What is the CLI?](#what-is-the-cli)
2. [Accessing the Command Line](#accessing-the-command-line)
3. [CLI Scripts Available in Phuppi](#cli-scripts-available-in-phuppi)
4. [Configuring Your Environment](#configuring-your-environment)
5. [Running the Queue Worker](#running-the-queue-worker)
6. [Regenerating Previews](#regenerating-previews)
7. [Common Questions and Troubleshooting](#common-questions-and-troubleshooting)

## What is the CLI?

The **CLI** (Command Line Interface) is a way to interact with your computer using text commands instead of clicking buttons and menus. Think of it as typing instructions to your computer instead of using a mouse.

Phuppi includes CLI scripts that help with:

- Processing file previews in the background
- Regenerating preview images if something goes wrong
- Managing the queue of files waiting to be processed

## Accessing the Command Line

### On Windows

1. Press the **Windows key** + **R** to open the Run dialog
2. Type `cmd` and press **Enter**
3. A black window called "Command Prompt" will open

### On macOS

1. Press **Command + Space** to open Spotlight
2. Type `Terminal` and press **Enter**
3. A Terminal window will open

### On Linux

1. Press **Ctrl + Alt + T** to open Terminal
2. Or look for "Terminal" in your applications menu

## CLI Scripts Available in Phuppi

Phuppi has two main CLI scripts located in the `src/bin/` directory:

| Script | Purpose |
|--------|---------|
| [`queue-worker.php`](src/bin/queue-worker.php) | Runs continuously to process preview images and videos in the background |
| [`regenerate-previews.php`](src/bin/regenerate-previews.php) | Regenerates all preview images (useful if previews are missing or corrupted) |

## Configuring Your Environment

Before running CLI scripts, you may need to configure your environment settings.

### Step 1: Copy the Example Environment File

1. Open your file explorer and navigate to your Phuppi folder
2. Find the file named `.env.example`
3. Make a copy of this file and rename it to `.env`

**Tip:** If you can't see files starting with a dot (like `.env.example`), you may need to enable "Show hidden files" in your file explorer settings.

### Step 2: Edit the .env File

Right-click on `.env` and open it with a text editor (like Notepad on Windows, TextEdit on macOS, or any code editor).

Here's what the file contains:

```bash
# Phuppi Environment Configuration
# Copy this file to .env and uncomment/adjust values as needed.
#
# Paths can be overridden here. If not set, defaults to:
# - views: src/views
# - root: project root (location of this file)
# - data: root/data
# - public: root/html
# - cache: data/cache
#
# PHUPPI_ROOT_PATH=/custom/project/root
# PHUPPI_VIEWS_PATH=/custom/views/path
# PHUPPI_DATA_PATH=/custom/data/path
# PHUPPI_PUBLIC_PATH=/custom/public/path
# PHUPPI_CACHE_PATH=/custom/cache/path

# MinIO Configuration
MINIO_ENDPOINT=http://localhost:9000
MINIO_ACCESS_KEY=phuppi_minio_test
MINIO_SECRET_KEY=phuppi_minio_secret_12345
MINIO_BUCKETS=phuppi-files
MINIO_PATH_PREFIX=data/uploadedFiles

# Database Configuration
# Add other environment variables as needed
```

### Understanding the Settings

| Setting | What It Does |
|---------|--------------|
| `PHUPPI_ROOT_PATH` | Where Phuppi is installed (usually auto-detected) |
| `PHUPPI_DATA_PATH` | Where Phuppi stores its data files |
| `MINIO_ENDPOINT` | Address of your cloud storage (if using S3/MinIO) |
| `MINIO_ACCESS_KEY` | Username for cloud storage access |
| `MINIO_SECRET_KEY` | Password for cloud storage access |
| `MINIO_BUCKETS` | The storage container name |

**For most users, you don't need to change anything!** The defaults work fine for basic installation.

## Running the Queue Worker

The **queue worker** is a script that runs continuously in the background, processing preview images and videos for files you upload.

### Why Do You Need It?

When you upload a file to Phuppi, it needs to create a preview image (like a thumbnail). The queue worker handles this processing so your uploads don't slow down the website.

### How to Start It

#### Using Docker (Recommended)

If you're running Phuppi with Docker:

```bash
docker compose exec phuppi php src/bin/queue-worker.php
```

To run it in the background:

```bash
docker compose exec -d phuppi php src/bin/queue-worker.php
```

#### Direct PHP Installation

If you have PHP installed directly on your computer:

```bash
cd /path/to/phuppi
php src/bin/queue-worker.php
```

### How to Stop It

Press **Ctrl + C** in the terminal window where it's running.

### What You'll See

When running, the queue worker displays information like:

```
Phuppi Preview Queue Worker v2.0.0
===================================

Starting queue worker (Press Ctrl+C to stop)...
```

It will show messages when processing files:

```
Processing image preview for: my-photo.jpg
✓ Image preview generated successfully
```

## Regenerating Previews

Sometimes preview images can become corrupted or go missing. The **regenerate-previews.php** script creates new previews for all your files.

### When to Use This

- Previews are showing broken images
- You've restored from a backup
- You've moved your installation to a new server
- Previews aren't appearing after upload

### How to Run It

#### Regenerate Image Previews Only (Default)

```bash
php src/bin/regenerate-previews.php
```

#### Regenerate Video Previews Only

```bash
php src/bin/regenerate-previews.php --video
```

#### Regenerate Both Image and Video Previews

```bash
php src/bin/regenerate-previews.php --all
```

#### Get Help

```bash
php src/bin/regenerate-previews.php --help
```

### What You'll See

```
Phuppi Preview Regenerator v2.0.0
==================================

Regenerating image previews...
Processing: my-document.pdf [1/50]
✓ Preview generated: my-document.pdf
Processing: my-photo.jpg [2/50]
✓ Preview generated: my-photo.jpg
...
Done! Processed 50 files successfully.
```

## Common Questions and Troubleshooting

**Q: I'm getting "command not found" errors**

**Problem:** Your system can't find PHP.

**Solution:** Make sure PHP is installed. On Docker, use `docker compose exec phuppi` before the command.

**Q: The queue worker keeps stopping**

**Problem:** The worker might be running out of memory or encountering errors.

**Solution:** Check the logs in `data/logs/` for error messages. You may need to increase PHP memory limits.

**Q: Previews aren't generating at all**

**Problem:** The queue worker isn't running.

**Solution:** Start the queue worker as described above. For shared hosting, enable AJAX processing in Settings.

**Q: I don't see the .env file**

**Problem:** Hidden files aren't visible.

**Solution:** Enable "Show hidden files" in your file explorer. Files starting with `.` are hidden by default.

**Q: How do I know if the queue worker is running?**

**Solution:** Check for the PID file at `data/queue-worker.pid`. If it exists, the worker is running.

**Q: Can I run multiple queue workers?**

**Yes!** For high-traffic sites, you can run multiple workers on different terminals for faster processing.

## Quick Reference Card

| Task | Command |
|------|---------|
| Start queue worker (Docker) | `docker compose exec phuppi php src/bin/queue-worker.php` |
| Start queue worker (direct) | `php src/bin/queue-worker.php` |
| Regenerate all image previews | `php src/bin/regenerate-previews.php` |
| Regenerate video previews | `php src/bin/regenerate-previews.php --video` |
| Regenerate everything | `php src/bin/regenerate-previews.php --all` |
| Get help | `php src/bin/regenerate-previews.php --help` |

## Need More Help?

- Check the [main README](../README.md) for installation guides
- Visit the [GitHub repository](https://github.com/AntzCode/phuppi/)
- Report issues at https://github.com/AntzCode

---

*Phuppi is maintained by AntzCode Ltd. For more information, visit https://www.antzcode.com*