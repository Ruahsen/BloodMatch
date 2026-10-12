<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/backend/src/autoload.php';

use BloodMatch\Config\Database;
use BloodMatch\Config\Env;
use BloodMatch\Repositories\ChatRepository;
use BloodMatch\Services\ChatService;

Env::load(BASE_PATH . '/.env');

echo "=== Seeding Demo Live Chat Conversations ===" . PHP_EOL;

$pdo = Database::pdo();
$chatRepo = new ChatRepository();
$service = new ChatService($chatRepo);

// Check users 2 and 3
$stmt = $pdo->prepare("SELECT id, full_name, email, role, account_status FROM users WHERE id IN (2, 3)");
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($users) < 2) {
    echo "Users 2 and 3 not found; skipping demo seed." . PHP_EOL;
    exit(0);
}

$officer = $users[0]['id'] == 2 ? $users[0] : $users[1];
$member = $users[0]['id'] == 3 ? $users[0] : $users[1];

// Find or create direct conversation between Officer and Member
$conv = $service->getOrCreateDirectConversation($officer, (int) $member['id']);
$convId = $conv['id'];

echo "Using Conversation ID {$convId} between {$officer['full_name']} and {$member['full_name']}." . PHP_EOL;

// Insert demo messages
$pdo->exec("DELETE FROM messages WHERE conversation_id = {$convId}");

$service->sendMessage(
    $officer,
    $convId,
    "Hello {$member['full_name']}! Thank you for stepping up to help with the urgent blood request at Bataan General Hospital."
);

$service->sendMessage(
    $member,
    $convId,
    "Glad to be able to help! What time does the blood station accept donors tomorrow?"
);

$service->sendMessage(
    $officer,
    $convId,
    "The blood bank opens at 8:00 AM. Please make sure to bring a valid ID and drink plenty of water before coming in."
);

// Set Member's last_read_at to before the last message, so Member has 1 unread message
$pdo->exec("
    UPDATE conversation_participants 
    SET last_read_at = DATE_SUB(NOW(), INTERVAL 5 MINUTE)
    WHERE conversation_id = {$convId} AND user_id = {$member['id']}
");

// Set Officer's last_read_at to NOW()
$pdo->exec("
    UPDATE conversation_participants 
    SET last_read_at = NOW()
    WHERE conversation_id = {$convId} AND user_id = {$officer['id']}
");

echo "Seeded 3 realistic messages. Member (ID {$member['id']}) now has 1 unread message from Officer (ID {$officer['id']})." . PHP_EOL;
echo "=== Done Seeding Demo Chat! ===" . PHP_EOL;
