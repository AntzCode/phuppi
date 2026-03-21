# Isolate Sessions and Queue Jobs to Independent SQLite Database Files

### Description

Currently, all application data is stored in a single SQLite database file (`data/phuppi.db`). This creates unnecessary risk by coupling transient data (sessions, queue jobs) with persistent application data. A corruption or issue in one subsystem could potentially affect the entire application.

This task involves refactoring the application to use separate SQLite database files for sessions and queue jobs, providing better isolation, easier maintenance, and improved recovery options.

---

### Motivation

1. **Failure Isolation** - A corrupted queue database won't affect user data or session state
2. **Easier Recovery** - Queue or session data can be reset independently without affecting the main application
3. **Reduced Contention** - High-frequency session/queue operations won't lock the main database
4. **Simplified Debugging** - Each subsystem's data can be inspected independently
5. **Defense in Depth** - Each storage boundary limits the blast radius of failures

---

### Scope

#### Files to Create/Modify:

| File | Action | Purpose |
|------|--------|---------|
| `data/sessions.db` | Create | Dedicated SQLite file for session data |
| `data/queue.db` | Create | Dedicated SQLite file for queue jobs |
| `src/Phuppi/DatabaseSession.php` | Modify | Connect to `sessions.db` instead of main DB |
| `src/Phuppi/Queue/QueueManager.php` | Modify | Connect to `queue.db` instead of main DB |
| `src/migrations/001_install_migration.php` | Modify | Create sessions table in `sessions.db` |
| `src/migrations/007_add_video_preview_jobs_table.php` | Modify | Create queue jobs table in `queue.db` |
| `src/bootstrap.php` | Modify | Update database connection initialization |
| `docker-compose.yml` | Modify | Add volume mappings for new database files |

#### Data Isolation Structure:
```
data/
├── phuppi.db      # Main application data (users, files, settings, etc.)
├── sessions.db    # User sessions only
└── queue.db       # Queue jobs only
```

---

### Implementation Steps

1. **Create new database files** - Initialize `sessions.db` and `queue.db` with appropriate schema
2. **Update DatabaseSession** - Modify to connect to `sessions.db` via PDO
3. **Update QueueManager** - Modify to connect to `queue.db` via PDO
4. **Update migrations** - Ensure migrations create tables in the correct database files
5. **Update bootstrap** - Initialize separate PDO connections for each database
6. **Update Docker configuration** - Add volume mappings for persistence
7. **Test isolation** - Verify each database operates independently
8. **Document changes** - Update documentation to reflect new data structure

---

### Backward Compatibility

- Existing `phuppi.db` remains unchanged for main application data
- Session and queue data migration from main DB to separate files should be handled during upgrade
- Configuration option to specify custom database paths for flexibility

---

### Testing Considerations

- Verify sessions persist correctly in `sessions.db`
- Verify queue jobs process correctly from `queue.db`
- Test failure scenarios (corrupted session/queue DB doesn't affect main app)
- Test data reset scenarios (clearing sessions/queue independently)
- Verify Docker volume persistence across container restarts

---

### Risk Assessment

| Risk | Mitigation |
|------|------------|
| Migration complexity | Provide migration script; test thoroughly |
| Connection overhead | Use persistent PDO connections |
| File management | Clear naming conventions and documentation |

---

### References

- Related: [`src/Phuppi/DatabaseSession.php`](src/Phuppi/DatabaseSession.php), [`src/Phuppi/Queue/QueueManager.php`](src/Phuppi/Queue/QueueManager.php)

---

### Labels

`enhancement` `database` `refactoring` `security`