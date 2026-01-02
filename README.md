# 🚀 Phuppi: Share Files & Notes Like a Boss!

**Phuppi** the PHP file-uppie thingy! It lets you share files and notes with friends or others and all locked down tight with top-notch security: passwords, vouchers and tokens. 

No fuss, just fun file sharing! 🎉

![Preview of Phuppi file uploader](./assets/screenshots/preview.png)

## ⚡ Quick Start – Up and Running in 2 Minutes

1️⃣ Grab the code: [from Github](https://github.com/AntzCode/phuppi/archive/refs/heads/main.zip)  
2️⃣ Unzip and upload to your server: [cPanel - how to upload files with FTP](https://docs.cpanel.net/knowledge-base/ftp/how-to-upload-files-with-ftp/)  
3️⃣ Open your website in your browser and set a username/password for the admin account  [(↗️ screenshot)](./assets/screenshots/installation.png)  

No database needed! (uses [SQLite](https://sqlite.org/))

## 🎯 Share in Seconds

1. Login.
2. Upload file or write note.
3. Generate token (add expiry if you want).
4. Share the link!

## 🔥 Why Phuppi Rocks

- **Zero-setup magic** – Docker does the heavy lifting.
- **Fort Knox security** – bcrypt passwords, one-time vouchers, expiring tokens.
- **Dead simple** – Upload, share, done. No tutorials needed.
- **All-in-one party** – Files, notes, auth, storage – bundled!

## 📋 Features That Wow

- Multiple File Uploader  [(↗️ screenshot)](./assets/screenshots/multiple-file-uploader.png)
- User Management and Flexible Permissions [(↗️ screenshot)](./assets/screenshots/users.png)
- Instant-access vouchers to easily share your upload rights with friends [(↗️ screenshot)](./assets/screenshots/vouchers.png)
- Flexible storage connectors - local filesystem or S3 [(↗️ screenshot)](./assets/screenshots/storage-connectors.png)
- Data migration tool for migrating data between S3 buckets [(↗️ screenshot)](./assets/screenshots/storage-connector-migration.png)
- De-duplicator tool for cleanup [(↗️ screenshot)](./assets/screenshots/duplicates.png)
- Token-based sharing for files & notes [(↗️ screenshot)](./assets/screenshots/share-link.png) [(↗️ screenshot)](./assets/screenshots/shared-note.png)
- Local filesystem storage or S3-compatible API support [(↗️ screenshot)](./assets/screenshots/active-storage-connector.png)
- Docker-ready configuration for dev & prod

## 🏗️ Under the Hood

- **Framework:** Flight micro-framework
- **DB:** SQLite (no config needed)
- **Storage:** Local or S3
- **Security:** bcrypt, sessions, tokens

## ⚡ Run on Docker for local development (takes just 2 Minutes)

```bash
# 1️⃣ Grab the code
git clone https://github.com/AntzCode/phuppi.git
cd phuppi

# 2️⃣ Fire up Docker
docker compose up -d --build

# 3️⃣ Set it up
docker exec -it phuppi php src/bootstrap.php install

# 4️⃣ Boom! Open http://localhost
```

**Pro tip:** Login with `admin@example.com` / `admin` – change it ASAP! 🔐

## 🤝 Contribute

Love it? Fork, tweak, PR! Check [`CONTRIBUTING.md`](CONTRIBUTING.md).

## 📄 License

GPLv3 – See [`LICENSE`](LICENSE). 

You are free to use, study, and modify this software. If you distribute the program or a derivative, you must provide the full source code and license the entire work under GPL‑v3 (or a later version), ensuring the same rights for downstream users.

---

*Ready to share securely? Let's go!* 🚀

