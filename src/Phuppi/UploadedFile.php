<?php

namespace Phuppi;

use Flight;

class UploadedFile
{
    public $id;
    public $user_id;
    public $voucher_id;
    public $filename;
    public $display_filename;
    public $filesize;
    public $mimetype;
    public $extension;
    public $uploaded_at;
    public $notes;

    protected ?User $ownerUser = null;

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

    public static function findById(int $id): ?self
    {
        $db = Flight::db();
        $stmt = $db->prepare('SELECT * FROM uploaded_files WHERE id = ?');
        $stmt->execute([$id]);
        $data = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $data ? new self($data) : null;
    }

    public static function findByUser(int $userId, $sort='date_desc', $limit=20, $offset=0): array
    {
        $db = Flight::db();
        $query = 'SELECT * FROM uploaded_files WHERE user_id = ?';
        switch($sort) {
            case 'date_desc':
                $query .= ' ORDER BY uploaded_at DESC';
                break;
            case 'date_asc':
                $query .= ' ORDER BY uploaded_at ASC';
                break;
        }
        if($limit > 0) {
            $query .= ' LIMIT ' . $limit;
            if($offset > 0) {
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

    public function delete(): bool
    {
        $db = Flight::db();
        $stmt = $db->prepare('DELETE FROM uploaded_files WHERE id = ?');
        return $stmt->execute([$this->id]);
    }

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
}