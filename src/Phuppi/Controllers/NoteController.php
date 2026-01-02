<?php

namespace Phuppi\Controllers;

use Flight;
use Phuppi\User;
use Phuppi\Voucher;
use Phuppi\Note;
use Phuppi\NoteToken;
use Phuppi\Permissions\NotePermission;

class NoteController
{

    public function index()
    {
        $permissionChecker = $this->getCurrentPermissionChecker();
        if(!$permissionChecker || !$permissionChecker->can(NotePermission::LIST)) {
            Flight::halt(403, 'Forbidden');
        }
        $lastActivity = Flight::session()->get('last_activity');
        // Check for session expiration (30 minutes)
        $sessionTimeout = 30 * 60; // 30 minutes
        if ($lastActivity && (time() - $lastActivity) > $sessionTimeout) {
            Flight::logger()->warning('Session expired due to inactivity, destroying session. Last activity: ' . date('Y-m-d H:i:s', $lastActivity));
            Flight::session()->clear();
            Flight::redirect('/login');
        }
        $user = Flight::user();
        $voucher = Flight::voucher();
        if ($voucher && $voucher->id) {
            $notes = Note::findByVoucher($voucher->id);
        } elseif ($user) {
            $notes = Note::findByUser($user->id);
        }  else {
            $notes = [];
        }
        Flight::render('notes.latte', ['notes' => $notes, 'name' => 'Notes']);
    }

    private function getCurrentUser(): ?User
    {
        $sessionId = Flight::session()->get('id');
        if ($sessionId) {
            return User::findById($sessionId);
        }
        return null;
    }

    private function getCurrentVoucher(): ?Voucher
    {
        // Assume voucher code is passed in header or param
        $voucher = Flight::voucher();
        if ($voucher && $voucher->id) {
            $voucher = Voucher::findById($voucher->id);
            if ($voucher && !$voucher->isExpired() && !$voucher->isDeleted()) {
                return $voucher;
            }
        }
        return null;
    }


    private function getCurrentPermissionChecker(): ?\Phuppi\PermissionChecker
    {
        $user = $this->getCurrentUser();
        $voucher = $this->getCurrentVoucher();

        if ($voucher && $voucher->id) {
            return \Phuppi\PermissionChecker::forVoucher($voucher);
        } elseif ($user) {
            return \Phuppi\PermissionChecker::forUser($user);
        }
        return null;
    }

    public function listNotes()
    {
        $permissionChecker = $this->getCurrentPermissionChecker();
        if (!$permissionChecker || !$permissionChecker->hasPermission(NotePermission::LIST)) {
            Flight::halt(403, 'Forbidden');
        }

        // Get query parameters
        $keyword = Flight::request()->query['keyword'] ?? '';
        $sort = Flight::request()->query['sort'] ?? 'date_newest';
        $page = (int)(Flight::request()->query['page'] ?? 1);
        $limit = (int)(Flight::request()->query['limit'] ?? 10);
        $offset = ($page - 1) * $limit;

        $user = $this->getCurrentUser();
        $voucher = $this->getCurrentVoucher();

        $result = Note::findFiltered(
            $user ? $user->id : null,
            $voucher ? $voucher->id : null,
            $keyword,
            $sort,
            $limit,
            $offset
        );

        $total = $result['total'];
        $totalPages = ceil($total / $limit);

        $noteData = array_map(function ($note) {
            return [
                'id' => $note->id,
                'filename' => $note->filename,
                'content' => substr($note->content, 0, 100) . (strlen($note->content) > 100 ? '...' : ''),
                'created_at' => $note->created_at,
                'updated_at' => $note->updated_at
            ];
        }, $result['notes']);

        Flight::json([
            'notes' => $noteData,
            'total' => $total,
            'totalPages' => $totalPages,
            'currentPage' => $page,
            'limit' => $limit
        ]);
    }

    public function getNote($id)
    {
        $note = Note::findById($id);

        if (!$note) {
            Flight::halt(404, 'Note not found');
        }

        // Check for token-based access
        $token = Flight::request()->query['token'] ?? null;
        if ($token) {
            $noteToken = NoteToken::findByToken($token);
            if (!$noteToken || $noteToken->note_id != $id) {
                Flight::halt(403, 'Invalid or expired token');
            }
        } else {
            $permissionChecker = $this->getCurrentPermissionChecker();
            if (!$permissionChecker || !$permissionChecker->can(NotePermission::VIEW, $note)) {
                Flight::halt(403, 'Forbidden');
            }
        }

        Flight::json([
            'id' => $note->id,
            'filename' => $note->filename,
            'content' => $note->content,
            'created_at' => $note->created_at,
            'updated_at' => $note->updated_at
        ]);
    }

    public function createNote()
    {
        $permissionChecker = $this->getCurrentPermissionChecker();
        if (!$permissionChecker || !$permissionChecker->hasPermission(NotePermission::CREATE)) {
            Flight::halt(403, 'Forbidden');
        }

        $data = Flight::request()->data;
        $filename = trim($data->filename ?? '');
        $content = $data->content ?? '';

        if (empty($filename)) {
            Flight::halt(400, 'Filename is required');
        }

        $voucher = $this->getCurrentVoucher();
        $user = $this->getCurrentUser();

        $note = new Note();
        $note->user_id = $user->id ?? $voucher->user_id;
        $note->voucher_id = $voucher->id ?? null;
        $note->filename = $filename;
        $note->content = $content;

        if ($note->save()) {
            Flight::json(['id' => $note->id, 'message' => 'Note created']);
        } else {
            Flight::halt(500, 'Failed to create note');
        }
    }

    public function updateNote($id)
    {
        $note = Note::findById($id);

        $permissionChecker = $this->getCurrentPermissionChecker();
        if (!$permissionChecker || !$permissionChecker->can(NotePermission::UPDATE, $note)) {
            Flight::halt(403, 'Forbidden');
        }

        if (!$note) {
            Flight::halt(404, 'Note not found');
        }

        $data = Flight::request()->data;
        $note->filename = trim($data->filename ?? $note->filename);
        $note->content = $data->content ?? $note->content;

        if ($note->save()) {
            Flight::json(['message' => 'Note updated']);
        } else {
            Flight::halt(500, 'Failed to update note');
        }
    }

    public function deleteNote($id)
    {
        $note = Note::findById($id);

        $permissionChecker = $this->getCurrentPermissionChecker();
        if (!$permissionChecker || !$permissionChecker->can(NotePermission::DELETE, $note)) {
            Flight::halt(403, 'Forbidden');
        }

        if (!$note) {
            Flight::halt(404, 'Note not found');
        }

        if ($note->delete()) {
            Flight::json(['message' => 'Note deleted']);
        } else {
            Flight::halt(500, 'Failed to delete note');
        }
    }

    public function generateShareToken($id)
    {
        $note = Note::findById($id);
        if (!$note) {
            Flight::halt(404, 'Note not found');
        }
        $permissionChecker = $this->getCurrentPermissionChecker();
        if (!$permissionChecker || !$permissionChecker->can(NotePermission::VIEW, $note)) {
            Flight::halt(403, 'Forbidden');
        }
        $data = Flight::request()->data;
        $duration = $data->duration ?? '1h'; // default 1 hour

        // Calculate expires_at
        $expiresAt = null;
        switch ($duration) {
            case '1h':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
                break;
            case '1d':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 day'));
                break;
            case '1w':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 week'));
                break;
            case '1m':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 month'));
                break;
            case '3m':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+3 months'));
                break;
            case '6m':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+6 months'));
                break;
            case '1y':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 year'));
                break;
            case '3y':
                $expiresAt = date('Y-m-d H:i:s', strtotime('+3 years'));
                break;
            case 'forever':
                $expiresAt = null;
                break;
            default:
                Flight::halt(400, 'Invalid duration');
        }

        // Generate unique token
        $token = bin2hex(random_bytes(32));

        $noteToken = new NoteToken();
        $noteToken->note_id = $note->id;
        $noteToken->voucher_id = $this->getCurrentVoucher() ? $this->getCurrentVoucher()->id : null;
        $noteToken->token = $token;
        $noteToken->expires_at = $expiresAt;

        if ($noteToken->save()) {
            $shareUrl = Flight::request()->getScheme() . '://' . Flight::request()->servername . '/notes/' . $note->id . '/shared?token=' . $token;
            Flight::json(['share_url' => $shareUrl, 'expires_at' => $expiresAt]);
        } else {
            Flight::halt(500, 'Failed to generate share token');
        }
    }

    public function showSharedNote($id)
    {

        $token = Flight::request()->query['token'] ?? null;
        if (!$token) {
            Flight::halt(403, 'Token required');
        }

        if(strlen($token) > 255) {
            Flight::halt(413, 'Invalid token');
        }
        
        $note = Note::findById($id);
        if (!$note) {
            Flight::halt(403, 'Invalid or expired token');
        }

        $noteToken = NoteToken::findByToken($token);
        if (!$noteToken || $noteToken->note_id != $id) {
            Flight::halt(403, 'Invalid or expired token');
        }

        Flight::render('shared_note.latte', ['note' => $note, 'expires_at' => $noteToken->expires_at]);
    }
}