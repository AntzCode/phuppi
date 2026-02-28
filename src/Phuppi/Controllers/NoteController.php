<?php

/**
 * NoteController.php
 *
 * NoteController class for handling note creation, retrieval, updates, and sharing in the Phuppi application.
 *
 * @package Phuppi\Controllers
 * @author Anthony Gallon
 * @copyright AntzCode Ltd
 * @license GPLv3
 * @link https://github.com/AntzCode/phuppi/
 * @since 2.0.0
 */

namespace Phuppi\Controllers;

use Flight;
use Phuppi\Helper;
use Phuppi\Note;
use Phuppi\NoteToken;
use Phuppi\Permissions\NotePermission;

class NoteController
{

    /**
     * Displays the notes index page.
     *
     * @return void
     */
    public function index(): void
    {
        $lastActivity = Flight::session()->get('last_activity');

        $user = Flight::user();
        $voucher = Flight::voucher();

        if ($voucher->id) {
            $notes = Note::findByVoucher($voucher->id);
        } elseif ($user) {
            $notes = Note::findByUser($user->id);
        } else {
            $notes = [];
        }

        Flight::render('notes.latte', ['notes' => $notes, 'name' => 'Notes']);
    }

    /**
     * Lists notes with filtering and pagination.
     *
     * @return void
     */
    public function listNotes(): void
    {
        // Get query parameters
        $keyword = Flight::request()->query['keyword'] ?? '';
        $sort = Flight::request()->query['sort'] ?? 'date_newest';
        $page = (int)(Flight::request()->query['page'] ?? 1);
        $limit = (int)(Flight::request()->query['limit'] ?? 10);
        $offset = ($page - 1) * $limit;

        $user = Flight::user();
        $voucher = Flight::voucher();

        $result = Note::findFiltered(
            $user->id ?: null,
            $voucher->id ?: null,
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

    /**
     * Gets a note by ID.
     *
     * @param int $id The note ID.
     * @return void
     */
    public function getNote($id): void
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
            if (!Helper::can(NotePermission::VIEW, $note)) {
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

    /**
     * Creates a new note.
     *
     * @return void
     */
    public function createNote(): void
    {
        $data = Flight::request()->data;
        $filename = trim($data->filename ?? '');
        $content = $data->content ?? '';

        if (empty($filename)) {
            Flight::halt(400, 'Filename is required');
        }

        $voucher = Flight::voucher();
        $user = Flight::user();

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

    /**
     * Updates a note.
     *
     * @param int $id The note ID.
     * @return void
     */
    public function updateNote($id): void
    {
        $note = Note::findById($id);

        if (!Helper::can(NotePermission::UPDATE, $note)) {
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

    /**
     * Deletes a note.
     *
     * @param int $id The note ID.
     * @return void
     */
    public function deleteNote($id): void
    {
        $note = Note::findById($id);

        if (!Helper::can(NotePermission::DELETE, $note)) {
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

    /**
     * Generates a share token for a note.
     *
     * @param int $id The note ID.
     * @return void
     */
    public function generateShareToken($id): void
    {
        $note = Note::findById($id);
        if (!$note) {
            Flight::halt(404, 'Note not found');
        }
        
        if (!Helper::can(NotePermission::VIEW, $note)) {
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

        $voucher = Flight::voucher();

        $noteToken = new NoteToken();
        $noteToken->note_id = $note->id;
        $noteToken->voucher_id = $voucher->id ?? null;
        $noteToken->token = $token;
        $noteToken->expires_at = $expiresAt;

        if ($noteToken->save()) {
            $shareUrl = Flight::request()->getScheme() . '://' . Flight::request()->servername . '/notes/' . $note->id . '/shared?token=' . $token;
            Flight::json(['share_url' => $shareUrl, 'expires_at' => $expiresAt]);
        } else {
            Flight::halt(500, 'Failed to generate share token');
        }
    }

    /**
     * Shows a shared note.
     *
     * @param int $id The note ID.
     * @return void
     */
    public function showSharedNote($id): void
    {
        $noteToken = NoteToken::findByToken(Flight::request()->query['token']);
        $note = Note::findById($id);
        Flight::render('shared_note.latte', ['note' => $note, 'expires_at' => $noteToken->expires_at]);
    }
}
