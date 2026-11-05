<?php
final class Db
{
    /** @var PDO|null */
    private static $pdo;

    public static function pdo(): PDO
    {
        if (!self::$pdo) {
            self::$pdo = new PDO(config('db.dsn'), config('db.user'), config('db.pass'), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            // توقيت القاعدة = توقيت PHP (المنطقة في config.php)
            self::$pdo->exec("SET time_zone = '" . date('P') . "'");
        }
        return self::$pdo;
    }

    public static function q(string $sql, array $p = []): PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($p);
        return $st;
    }

    public static function one(string $sql, array $p = []): ?array
    {
        $r = self::q($sql, $p)->fetch();
        return $r === false ? null : $r;
    }

    public static function all(string $sql, array $p = []): array
    {
        return self::q($sql, $p)->fetchAll();
    }

    public static function val(string $sql, array $p = [])
    {
        $v = self::q($sql, $p)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function insert(string $table, array $row): int
    {
        $cols = array_keys($row);
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        self::q($sql, array_values($row));
        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $row, string $where, array $p = []): int
    {
        $set = [];
        foreach (array_keys($row) as $c) {
            $set[] = '`' . $c . '` = ?';
        }
        $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $set) . ' WHERE ' . $where;
        return self::q($sql, array_merge(array_values($row), $p))->rowCount();
    }

    /** ينفّذ الدالة داخل معاملة؛ أي استثناء يلغي كل التغييرات */
    public static function tx(callable $fn)
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $r = $fn();
            $pdo->commit();
            return $r;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
