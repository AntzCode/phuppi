<?php

/**
 * Note.php
 *
 * Note class for managing notes in the Phuppi application.
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

class Note
{
    /** @var int|null The unique identifier for the note. */
    public $id;

    /** @var int|null The user ID associated with the note. */
    public $user_id;

    /** @var int|null The voucher ID associated with the note. */
    public $voucher_id;

    /** @var string The filename of the note. */
    public $filename;

    /** @var string The content of the note. */
    public $content;

    /** @var string|null The creation timestamp of the note. */
    public $created_at;

    /** @var string|null The last update timestamp of the note. */
    public $updated_at;

    /**
     * Constructs a Note object with optional data.
     * 
     * @param array $data Optional data to initialize the note.
     */
    public function __construct(array $data = [])
    {
        $this->id = $data['id'] ?? null;
        $this->user_id = $data['user_id'] ?? null;
        $this->voucher_id = $data['voucher_id'] ?? null;
        $this->filename = $data['filename'] ?? '';
        $this->content = $data['content'] ?? '';
        $this->created_at = $data['created_at'] ?? null;
        $this->updated_at = $data['updated_at'] ?? null;
    }

    /**
     * Loads a note from the database by ID.
     * 
     * @param int $id The note ID.
     * @return bool True if the note was loaded, false otherwise.
     */
    public function load(int $id): bool
    {
        $db = Flight::db();
        $stmt = $db->prepare('SELECT * FROM notes WHERE id = ?');
        $stmt->execute([$id]);
        $data = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($data) {
            $this->id = $data['id'];
            $this->user_id = $data['user_id'];
            $this->voucher_id = $data['voucher_id'];
            $this->filename = $data['filename'];
            $this->content = $data['content'];
            $this->created_at = $data['created_at'];
            $this->updated_at = $data['updated_at'];
            return true;
        }
        return false;
    }

    /**
     * Finds a note by ID.
     * 
     * @param int $id The note ID.
     * @return self|null The note object or null if not found.
     */
    public static function findById(int $id): ?self
    {
        $db = Flight::db();
        $stmt = $db->prepare('SELECT * FROM notes WHERE id = ?');
        $stmt->execute([$id]);
        $data = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $data ? new self($data) : null;
    }

    /**
     * Finds notes by user ID with sorting and pagination.
     * 
     * @param int $userId The user ID.
     * @param string $sort The sort order ('date_desc' or 'date_asc').
     * @param int $limit The number of notes to return.
     * @param int $offset The offset for pagination.
     * @return array Array of Note objects.
     */
    public static function findByUser(int $userId, $sort = 'date_desc', $limit = 20, $offset = 0): array
    {
        $db = Flight::db();
        $query = 'SELECT * FROM notes WHERE user_id = ?';
        switch ($sort) {
            case 'date_desc':
                $query .= ' ORDER BY created_at DESC';
                break;
            case 'date_asc':
                $query .= ' ORDER BY created_at ASC';
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
        $notes = [];
        while ($data = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $notes[] = new self($data);
        }
        return $notes;
    }

    /**
     * Finds notes by voucher ID.
     * 
     * @param int $voucherId The voucher ID.
     * @return array Array of Note objects.
     */
    public static function findByVoucher(int $voucherId): array
    {
        $db = Flight::db();
        $stmt = $db->prepare('SELECT * FROM notes WHERE voucher_id = ?');
        $stmt->execute([$voucherId]);
        $notes = [];
        while ($data = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $notes[] = new self($data);
        }
        return $notes;
    }

    /**
     * Finds notes with filtering, sorting, and pagination.
     * 
     * @param int|null $userId The user ID to filter by.
     * @param int|null $voucherId The voucher ID to filter by.
     * @param string $keyword Keyword to search in filename or content.
     * @param string $sort The sort order.
     * @param int $limit The number of notes to return.
     * @param int $offset The offset for pagination.
     * @return array Array with 'notes' and 'total' keys.
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
            $where[] = '(filename LIKE ? OR content LIKE ?)';
            $params[] = '%' . $keyword . '%';
            $params[] = '%' . $keyword . '%';
        }
        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Build ORDER BY
        $orderBy = 'ORDER BY ';
        switch ($sort) {
            case 'date_oldest':
                $orderBy .= 'created_at ASC';
                break;
            case 'date_newest':
                $orderBy .= 'created_at DESC';
                break;
            case 'filename_up':
                $orderBy .= 'filename ASC';
                break;
            case 'filename_down':
                $orderBy .= 'filename DESC';
                break;
            default:
                $orderBy .= 'created_at DESC';
        }

        // Get total count
        $countQuery = 'SELECT COUNT(*) as total FROM notes ' . $whereClause;
        $countStmt = $db->prepare($countQuery);
        $countStmt->execute($params);
        $total = $countStmt->fetch(\PDO::FETCH_ASSOC)['total'];

        // Get notes
        $query = 'SELECT * FROM notes ' . $whereClause . ' ' . $orderBy . ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        $notes = [];
        while ($data = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $notes[] = new self($data);
        }

        return ['notes' => $notes, 'total' => $total];
    }

    /**
     * Saves the note to the database.
     * 
     * @return bool True if saved successfully, false otherwise.
     */
    public function save(): bool
    {
        $db = Flight::db();
        if ($this->id) {
            // Update
            $stmt = $db->prepare('UPDATE notes SET user_id = ?, voucher_id = ?, filename = ?, content = ?, updated_at = ? WHERE id = ?');
            return $stmt->execute([
                $this->user_id,
                $this->voucher_id,
                $this->filename,
                $this->content,
                date('Y-m-d H:i:s'),
                $this->id
            ]);
        } else {
            // Insert
            $stmt = $db->prepare('INSERT INTO notes (user_id, voucher_id, filename, content, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
            $now = date('Y-m-d H:i:s');
            $result = $stmt->execute([
                $this->user_id,
                $this->voucher_id,
                $this->filename,
                $this->content,
                $now,
                $now
            ]);
            if ($result) {
                $this->id = $db->lastInsertId();
                $this->created_at = $now;
                $this->updated_at = $now;
            }
            return $result;
        }
    }

    /**
     * Deletes the note from the database.
     *  
     * @return bool True if deleted successfully, false otherwise.
     */
    public function delete(): bool
    {
        $db = Flight::db();
        $stmt = $db->prepare('DELETE FROM notes WHERE id = ?');
        return $stmt->execute([$this->id]);
    }
}
