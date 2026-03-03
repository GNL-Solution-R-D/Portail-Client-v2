<?php
use PHPUnit\Framework\TestCase;
use App\UserRepository;

class UserRepositoryTest extends TestCase
{
    private PDO $pdo;
    private UserRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createSchema();
        $this->repo = new UserRepository($this->pdo);
    }

    private function createSchema(): void
    {
        $this->pdo->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            siren TEXT UNIQUE,
            name TEXT,
            structure_type TEXT,
            password TEXT
        )');
        $this->pdo->exec('CREATE TABLE admin_contacts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            legal_representative TEXT,
            admin_email TEXT
        )');
    }

    public function testUserRegistrationAndAuthentication(): void
    {
        $userId = $this->repo->createUser('123', 'Test', 'Association', 'secret');
        $this->assertIsInt($userId);

        $user = $this->repo->authenticate('123', 'secret');
        $this->assertNotNull($user);
        $this->assertSame('Test', $user['name']);

        $invalid = $this->repo->authenticate('123', 'wrong');
        $this->assertNull($invalid);
    }

    public function testUpdatePassword(): void
    {
        $userId = $this->repo->createUser('123', 'Test', 'Association', 'secret');
        $this->repo->updatePassword($userId, 'newpass');
        $this->assertNotNull($this->repo->authenticate('123', 'newpass'));
        $this->assertNull($this->repo->authenticate('123', 'secret'));
    }

    public function testAdminContactInsertAndUpdate(): void
    {
        $userId = $this->repo->createUser('123', 'Test', 'Association', 'secret');
        $this->repo->updateAdminContact($userId, 'John', 'john@example.com');
        $stmt = $this->pdo->query('SELECT * FROM admin_contacts WHERE user_id='.$userId);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('John', $row['legal_representative']);

        // update
        $this->repo->updateAdminContact($userId, 'Jane', 'jane@example.com');
        $stmt = $this->pdo->query('SELECT * FROM admin_contacts WHERE user_id='.$userId);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('Jane', $row['legal_representative']);
        $this->assertSame('jane@example.com', $row['admin_email']);
    }
}
