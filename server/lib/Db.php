<?php
declare(strict_types=1);

final class Db
{
    public function __construct(public PDO $pdo, public string $driver)
    {
    }

    public function all(string $sql, array $params = []): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public function one(string $sql, array $params = []): ?array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public function exec(string $sql, array $params = []): int
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public function insert(string $sql, array $params = []): int
    {
        $this->exec($sql, $params);
        return (int) $this->pdo->lastInsertId();
    }

    public function tx(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}

final class HttpError extends RuntimeException
{
    public function __construct(
        public int $status,
        string $message,
        public string $codeName = 'error',
    ) {
        parent::__construct($message);
    }
}
