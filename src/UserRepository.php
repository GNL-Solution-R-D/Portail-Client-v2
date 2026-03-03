<?php
namespace App;

use PDO;

class UserRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function createUser(string $siren, string $name, string $type, string $password): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO users (siren, name, structure_type, password) VALUES (?, ?, ?, ?)");
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt->execute([$siren, $name, $type, $hash]);
        return (int)$this->pdo->lastInsertId();
    }

    public function authenticate(string $siren, string $password): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE siren = ?");
        $stmt->execute([$siren]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user && password_verify($password, $user['password'])) {
            return $user;
        }
        return null;
    }

    public function updatePassword(int $userId, string $newPassword): void
    {
        $stmt = $this->pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        $stmt->execute([$hash, $userId]);
    }

    public function updateAdminContact(int $userId, string $legalRep, string $email): void
    {
        $query = $this->pdo->prepare("SELECT id FROM admin_contacts WHERE user_id = ?");
        $query->execute([$userId]);
        if ($query->fetch()) {
            $update = $this->pdo->prepare("UPDATE admin_contacts SET legal_representative = ?, admin_email = ? WHERE user_id = ?");
            $update->execute([$legalRep, $email, $userId]);
        } else {
            $insert = $this->pdo->prepare("INSERT INTO admin_contacts (user_id, legal_representative, admin_email) VALUES (?, ?, ?)");
            $insert->execute([$userId, $legalRep, $email]);
        }
    }
}
