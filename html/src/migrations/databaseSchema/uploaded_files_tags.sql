CREATE TABLE `fuppi_uploaded_files_tags` (
    `uploaded_file_tag_id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    `uploaded_file_id` INTEGER NOT NULL,
    `tag_id` INTEGER NULL,
    FOREIGN KEY (uploaded_file_id) REFERENCES fuppi_uploaded_files (uploaded_file_id),
    FOREIGN KEY (tag_id) REFERENCES fuppi_tags (tag_id)
);