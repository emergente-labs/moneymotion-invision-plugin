<?php
/**
 * Simple SQLite database for tracking checkout sessions
 * No MySQL needed - works everywhere PHP runs
 */
class Database
{
    private $pdo;

    public function __construct($dbPath)
    {
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->pdo = new PDO('sqlite:' . $dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createTables();
    }

    private function createTables()
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS checkout_sessions (
                session_id      TEXT PRIMARY KEY,
                invoice_id      INTEGER NOT NULL,
                member_id       INTEGER NOT NULL DEFAULT 0,
                email           TEXT NOT NULL DEFAULT '',
                description     TEXT NOT NULL DEFAULT '',
                amount_cents    INTEGER NOT NULL DEFAULT 0,
                currency        TEXT NOT NULL DEFAULT 'EUR',
                status          TEXT NOT NULL DEFAULT 'pending',
                created_at      INTEGER NOT NULL,
                updated_at      INTEGER NOT NULL
            )
        ");
    }

    /**
     * Store a new checkout session
     */
    public function saveSession($sessionId, array $data)
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO checkout_sessions (session_id, invoice_id, member_id, email, description, amount_cents, currency, status, created_at, updated_at)
            VALUES (:session_id, :invoice_id, :member_id, :email, :description, :amount_cents, :currency, 'pending', :now, :now)
        ");
        $stmt->execute(array(
            ':session_id'   => $sessionId,
            ':invoice_id'   => $data['invoice_id'],
            ':member_id'    => isset($data['member_id']) ? $data['member_id'] : 0,
            ':email'        => $data['email'],
            ':description'  => $data['description'],
            ':amount_cents' => $data['amount_cents'],
            ':currency'     => isset($data['currency']) ? $data['currency'] : 'EUR',
            ':now'          => time(),
        ));
    }

    /**
     * Get a session by ID
     */
    public function getSession($sessionId)
    {
        $stmt = $this->pdo->prepare("SELECT * FROM checkout_sessions WHERE session_id = :id");
        $stmt->execute(array(':id' => $sessionId));
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Update session status
     */
    public function updateStatus($sessionId, $status)
    {
        $stmt = $this->pdo->prepare("UPDATE checkout_sessions SET status = :status, updated_at = :now WHERE session_id = :id");
        $stmt->execute(array(
            ':status'   => $status,
            ':now'      => time(),
            ':id'       => $sessionId,
        ));
    }

    /**
     * Get session by invoice ID
     */
    public function getSessionByInvoice($invoiceId)
    {
        $stmt = $this->pdo->prepare("SELECT * FROM checkout_sessions WHERE invoice_id = :id ORDER BY created_at DESC LIMIT 1");
        $stmt->execute(array(':id' => $invoiceId));
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
