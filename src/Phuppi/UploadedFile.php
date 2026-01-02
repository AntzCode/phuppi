<?php

/**
 * UploadedFile.php
 *
 * UploadedFile class for handling file uploads and metadata in the Phuppi application.
 *
 * @package Phuppi
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.0.0
 */

namespace Phuppi;

use Flight;

class UploadedFile
{
    /** @var int|null */
    public $id;

    /** @var int|null */
    public $user_id;

    /** @var int|null */
    public $voucher_id;

    /** @var string */
    public $filename;

    /** @var string */
    public $display_filename;

    /** @var int */
    public $filesize;

    /** @var string */
    public $mimetype;

    /** @var string */
    public $extension;

    /** @var string|null */
    public $uploaded_at;

    /** @var string */
    public $notes;

    /** @var ?User */
    protected ?User $ownerUser = null;

    /**
     * Constructor for UploadedFile.
     *
     * @param array $data Initial data to populate the object.
     */
    public function __construct(array $data = [])
    {
        $this->id = $data['id'] ?? null;
        $this->user_id = $data['user_id'] ?? null;
        $this->voucher_id = $data['voucher_id'] ?? null;
        $this->filename = $data['filename'] ?? '';
        $this->display_filename = $data['display_filename'] ?? '';
        $this->filesize = $data['filesize'] ?? 0;
        $this->mimetype = $data['mimetype'] ?? '';
        $this->extension = $data['extension'] ?? '';
        $this->uploaded_at = $data['uploaded_at'] ?? null;
        $this->notes = $data['notes'] ?? '';
    }

    /**
     * Loads a file by its ID.
     *
     * @param int $id The ID of the file.
     * @return bool True if loaded successfully, false otherwise.
     */
    public function load(int $id): bool
    {
        $db = Flight::db();
        $stmt = $db->prepare('SELECT * FROM uploaded_files WHERE id = ?');
        $stmt->execute([$id]);
        $data = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($data) {
            $this->id = $data['id'];
            $this->user_id = $data['user_id'];
            $this->voucher_id = $data['voucher_id'];
            $this->filename = $data['filename'];
            $this->display_filename = $data['display_filename'];
            $this->filesize = $data['filesize'];
            $this->mimetype = $data['mimetype'];
            $this->extension = $data['extension'];
            $this->uploaded_at = $data['uploaded_at'];
            $this->notes = $data['notes'];
            return true;
        }
        return false;
    }

    /**
     * Finds a file by its ID.
     *
     * @param int $id The ID of the file.
     * @return self|null The file object if found, null otherwise.
     */
    public static function findById(int $id): ?self
    {
        $db = Flight::db();
        $stmt = $db->prepare('SELECT * FROM uploaded_files WHERE id = ?');
        $stmt->execute([$id]);
        $data = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $data ? new self($data) : null;
    }

    /**
     * Finds files by user ID with sorting and pagination.
     *
     * @param int $userId The user ID.
     * @param string $sort The sort order ('date_desc', 'date_asc').
     * @param int $limit The number of files to return.
     * @param int $offset The offset for pagination.
     * @return array Array of UploadedFile objects.
     */
    public static function findByUser(int $userId, $sort = 'date_desc', $limit = 20, $offset = 0): array
    {
        $db = Flight::db();
        $query = 'SELECT * FROM uploaded_files WHERE user_id = ?';

        switch ($sort) {
            case 'date_desc':
                $query .= ' ORDER BY uploaded_at DESC';
                break;
            case 'date_asc':
                $query .= ' ORDER BY uploaded_at ASC';
                break;
        }

        if ($limit > 0) {
            $query .= ' LIMIT ' . $limit;
            if ($offset > 0) {
                $query .= ' OFFSET ' . $offset;
            }
        }

        $stmt = $db->prepare($query);
        $stmt->execute([$userId]);
        $files = [];

        while ($data = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $files[] = new self($data);
        }

        return $files;
    }

    /**
     * Finds files by voucher ID.
     *
     * @param int $voucherId The voucher ID.
     * @return array Array of UploadedFile objects.
     */
    public static function findByVoucher(int $voucherId): array
    {
        $db = Flight::db();
        $stmt = $db->prepare('SELECT * FROM uploaded_files WHERE voucher_id = ?');
        $stmt->execute([$voucherId]);

        $files = [];

        while ($data = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $files[] = new self($data);
        }

        return $files;
    }

    /**
     * Finds files with filters, sorting, and pagination.
     *
     * @param ?int $userId The user ID to filter by.
     * @param ?int $voucherId The voucher ID to filter by.
     * @param string $keyword Keyword to search in display_filename and notes.
     * @param string $sort Sort order ('size_smallest', 'size_largest', 'date_oldest', 'date_newest', 'filename_up', 'filename_down').
     * @param int $limit Number of files to return.
     * @param int $offset Offset for pagination.
     * @return array Array with 'files' and 'total' keys.
     */
    public static function findFiltered(?int $userId, ?int $voucherId, string $keyword = '', string $sort = 'date_newest', int $limit = 10, int $offset = 0): array
    {
        $db = Flight::db();

        // Build WHERE clause
        $where = [];
        $params = [];

        if ($userId) {
            $where[] = 'user_id = ?';
            $params[] = $userId;
        } elseif ($voucherId) {
            $where[] = 'voucher_id = ?';
            $params[] = $voucherId;
        }

        if (!empty($keyword)) {
            $where[] = '(display_filename LIKE ? OR notes LIKE ?)';
            $params[] = '%' . $keyword . '%';
            $params[] = '%' . $keyword . '%';
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Build ORDER BY
        $orderBy = 'ORDER BY ';
        switch ($sort) {
            case 'size_smallest':
                $orderBy .= 'filesize ASC';
                break;
            case 'size_largest':
                $orderBy .= 'filesize DESC';
                break;
            case 'date_oldest':
                $orderBy .= 'uploaded_at ASC';
                break;
            case 'date_newest':
                $orderBy .= 'uploaded_at DESC';
                break;
            case 'filename_up':
                $orderBy .= 'display_filename ASC';
                break;
            case 'filename_down':
                $orderBy .= 'display_filename DESC';
                break;
            default:
                $orderBy .= 'uploaded_at DESC';
        }

        // Get total count
        $countQuery = 'SELECT COUNT(*) as total FROM uploaded_files ' . $whereClause;
        $countStmt = $db->prepare($countQuery);
        $countStmt->execute($params);
        $total = $countStmt->fetch(\PDO::FETCH_ASSOC)['total'];

        // Get files
        $query = 'SELECT * FROM uploaded_files ' . $whereClause . ' ' . $orderBy . ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        $files = [];
        while ($data = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $files[] = new self($data);
        }

        return ['files' => $files, 'total' => $total];
    }

    /**
     * Saves the file to the database.
     *
     * @return bool True if saved successfully, false otherwise.
     */
    public function save(): bool
    {
        $db = Flight::db();
        if ($this->id) {
            // Update

            $stmt = $db->prepare('UPDATE uploaded_files SET user_id = ?, voucher_id = ?, filename = ?, display_filename = ?, filesize = ?, mimetype = ?, extension = ?, notes = ? WHERE id = ?');
            
            return $stmt->execute([
                $this->user_id,
                $this->voucher_id,
                $this->filename,
                $this->display_filename,
                $this->filesize,
                $this->mimetype,
                $this->extension,
                $this->notes,
                $this->id
            ]);

        } else {
            // Insert

            $stmt = $db->prepare('INSERT INTO uploaded_files (user_id, voucher_id, filename, display_filename, filesize, mimetype, extension, uploaded_at, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $now = date('Y-m-d H:i:s');
            
            $result = $stmt->execute([
                $this->user_id,
                $this->voucher_id,
                $this->filename,
                $this->display_filename,
                $this->filesize,
                $this->mimetype,
                $this->extension,
                $now,
                $this->notes
            ]);

            if ($result) {
                $this->id = $db->lastInsertId();
                $this->uploaded_at = $now;
            }

            return $result;
        }
    }

    /**
     * Deletes the file from the database.
     *
     * @return bool True if deleted successfully, false otherwise.
     */
    public function delete(): bool
    {
        $db = Flight::db();
        $stmt = $db->prepare('DELETE FROM uploaded_files WHERE id = ?');
        return $stmt->execute([$this->id]);
    }

    /**
     * Gets the username associated with the file (user or voucher).
     *
     * @return string The username or voucher code prefixed with 'voucher_'.
     */
    public function getUsername(): string
    {
        if ($this->user_id) {
            if (!$this->ownerUser instanceof User) {
                $this->ownerUser = User::findById($this->user_id);
            }

            if ($this->ownerUser) {
                return $this->ownerUser->username;
            } else {
                Flight::logger()->warning('getUsername: User not found for user_id: ' . $this->user_id);
                return '';
            }

        } elseif ($this->voucher_id) {

            $voucher = new \Phuppi\Voucher();
            
            if ($voucher->load($this->voucher_id)) {
                return 'voucher_' . $voucher->voucher_code;
            } else {
                Flight::logger()->warning('getUsername: Voucher not found for voucher_id: ' . $this->voucher_id);
                return '';
            }
        }
        return '';
    }

    /**
     * Gets the voucher code if the file is associated with a voucher.
     *
     * @return ?string The voucher code or null if not associated with a voucher.
     */
    public function getVoucherCode(): ?string
    {
        if ($this->voucher_id) {
            $voucher = new \Phuppi\Voucher();
            if ($voucher->load($this->voucher_id)) {
                return $voucher->voucher_code;
            }
        }
        return null;
    }
}
