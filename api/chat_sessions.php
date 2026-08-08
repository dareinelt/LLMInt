<?php

/**
 * api/chat_sessions.php
 *
 * JSON API for managing a logged-in user's conversation sessions.
 *
 * Requires an active PHP session with admin_id set (same session as
 * the admin / user login).
 *
 * Actions (GET or POST parameter "action"):
 *   list   – Return all sessions for the current user (newest first).
 *   load   – Return the message history for a specific session_id.
 *   delete – Delete a specific session owned by the current user.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');

// Only registered, logged-in users may access session data.
if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Nicht angemeldet.']);
    exit;
}

$userId = (int) $_SESSION['admin_id'];
$action = $_REQUEST['action'] ?? '';

// ── list ──────────────────────────────────────────────────────────────────────
if ($action === 'list') {
    echo json_encode(listUserConversations($userId));
    exit;
}

// ── load ──────────────────────────────────────────────────────────────────────
if ($action === 'load') {
    $rawId = $_REQUEST['session_id'] ?? '';
    if (!preg_match('/^[a-f0-9]{8,128}$/', $rawId)) {
        http_response_code(400);
        echo json_encode(['error' => 'Ungültige Session-ID.']);
        exit;
    }
    try {
        $stmt = getDb()->prepare(
            'SELECT session_id, title, model, messages, group_label
               FROM conversation_sessions
              WHERE session_id = ? AND user_id = ?'
        );
        $stmt->execute([$rawId, $userId]);
        $row = $stmt->fetch();
        if ($row === false) {
            http_response_code(404);
            echo json_encode(['error' => 'Session nicht gefunden.']);
            exit;
        }
        $messages = json_decode((string) $row['messages'], true);
        echo json_encode([
            'session_id' => $row['session_id'],
            'title'      => $row['title'],
            'model'      => $row['model'],
            'messages'   => is_array($messages) ? $messages : [],
            'intelligence_group' => (string) ($row['group_label'] ?? ''),
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Datenbankfehler.']);
    }
    exit;
}

// ── delete ────────────────────────────────────────────────────────────────────
if ($action === 'delete') {
    $rawId = $_REQUEST['session_id'] ?? '';
    if (!preg_match('/^[a-f0-9]{8,128}$/', $rawId)) {
        http_response_code(400);
        echo json_encode(['error' => 'Ungültige Session-ID.']);
        exit;
    }
    try {
        getDb()->prepare(
            'DELETE FROM conversation_sessions WHERE session_id = ? AND user_id = ?'
        )->execute([$rawId, $userId]);
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Datenbankfehler.']);
    }
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unbekannte Aktion.']);
