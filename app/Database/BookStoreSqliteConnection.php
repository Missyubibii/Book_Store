<?php

declare(strict_types=1);

namespace App\Database;

use Closure;
use Illuminate\Database\SQLiteConnection;
use Throwable;

/**
 * Kết nối SQLite tùy biến bảo đảm `transaction_mode => 'IMMEDIATE'` hoạt động nhất quán trên PHP 8.3+.
 *
 * Trong `Illuminate\Database\SQLiteConnection` mặc định, tùy chọn `transaction_mode` chỉ chạy
 * trên PHP >= 8.4 do khác biệt `PDO::inTransaction()` khi dùng `BEGIN IMMEDIATE TRANSACTION`.
 * Lớp này giữ nguyên hành vi giao dịch lồng nhau (`SAVEPOINT`) và hoàn tác an toàn trên PHP 8.3.
 */
class BookStoreSqliteConnection extends SQLiteConnection
{
    private bool $manualSqliteTransactionActive = false;

    /**
     * Thực thi closure trong giao dịch SQLite, hỗ trợ commit giao dịch `BEGIN IMMEDIATE` trên PHP 8.3.
     *
     * @template TReturn of mixed
     *
     * @param  Closure(static): TReturn  $callback
     * @param  int  $attempts
     * @return TReturn
     *
     * @throws Throwable
     */
    public function transaction(Closure $callback, $attempts = 1)
    {
        for ($currentAttempt = 1; $currentAttempt <= $attempts; $currentAttempt++) {
            $this->beginTransaction();

            try {
                $callbackResult = $callback($this);
            } catch (Throwable $e) {
                $this->handleTransactionException($e, $currentAttempt, $attempts);

                continue;
            }

            $levelBeingCommitted = $this->transactions;

            try {
                if ($this->transactions === 1) {
                    $this->fireConnectionEvent('committing');
                    $this->commitRootPdoTransaction();
                }

                $this->transactions = max(0, $this->transactions - 1);
            } catch (Throwable $e) {
                $this->handleCommitTransactionException($e, $currentAttempt, $attempts);

                continue;
            }

            $this->transactionsManager?->commit(
                $this->getName(),
                $levelBeingCommitted,
                $this->transactions,
            );

            $this->fireConnectionEvent('committed');

            return $callbackResult;
        }
    }

    /**
     * Commit giao dịch đang hoạt động, xử lý đúng giao dịch `BEGIN IMMEDIATE`.
     */
    public function commit(): void
    {
        if ($this->transactionLevel() === 1) {
            $this->fireConnectionEvent('committing');
            $this->commitRootPdoTransaction();
        }

        [$levelBeingCommitted, $this->transactions] = [
            $this->transactions,
            max(0, $this->transactions - 1),
        ];

        $this->transactionsManager?->commit(
            $this->getName(),
            $levelBeingCommitted,
            $this->transactions,
        );

        $this->fireConnectionEvent('committed');
    }

    /**
     * Khởi tạo giao dịch gốc bằng `BEGIN IMMEDIATE TRANSACTION` khi được cấu hình.
     */
    protected function executeBeginTransactionStatement(): void
    {
        if (version_compare(PHP_VERSION, '8.4.0', '>=')) {
            $this->manualSqliteTransactionActive = false;
            parent::executeBeginTransactionStatement();

            return;
        }

        $mode = strtoupper((string) ($this->getConfig('transaction_mode') ?? 'DEFERRED'));

        if (in_array($mode, ['IMMEDIATE', 'EXCLUSIVE'], true)) {
            $this->getPdo()->exec("BEGIN {$mode} TRANSACTION");
            $this->manualSqliteTransactionActive = true;

            return;
        }

        $this->manualSqliteTransactionActive = false;
        $this->getPdo()->beginTransaction();
    }

    /**
     * Hoàn tác giao dịch gốc hoặc `SAVEPOINT` lồng nhau một cách an toàn.
     */
    protected function performRollBack($toLevel): void
    {
        if ($toLevel === 0) {
            if ($this->manualSqliteTransactionActive) {
                $this->rollBackManualSqliteTransactionSafely();

                return;
            }

            $pdo = $this->getPdo();

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return;
        }

        if ($this->queryGrammar->supportsSavepoints()) {
            $this->getPdo()->exec(
                $this->queryGrammar->compileSavepointRollBack('trans'.($toLevel + 1)),
            );
        }
    }

    /**
     * Xử lý ngoại lệ khi commit giao dịch (bao gồm cả giao dịch `BEGIN IMMEDIATE`).
     */
    protected function handleCommitTransactionException(Throwable $e, $currentAttempt, $maxAttempts): void
    {
        $this->transactions = max(0, $this->transactions - 1);

        if ($this->causedByConcurrencyError($e) && $currentAttempt < $maxAttempts) {
            if ($this->manualSqliteTransactionActive) {
                $this->rollBackManualSqliteTransactionSafely();
            } else {
                $pdo = $this->getPdo();

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            }

            return;
        }

        if ($this->causedByLostConnection($e)) {
            $this->transactions = 0;
            $this->manualSqliteTransactionActive = false;
        }

        throw $e;
    }

    private function commitRootPdoTransaction(): void
    {
        if ($this->manualSqliteTransactionActive) {
            try {
                $this->getPdo()->exec('COMMIT');
            } finally {
                $this->manualSqliteTransactionActive = false;
            }

            return;
        }

        $this->getPdo()->commit();
    }

    private function rollBackManualSqliteTransactionSafely(): void
    {
        try {
            $this->getPdo()->exec('ROLLBACK');
        } catch (Throwable) {
            // SQLite có thể đã tự động đóng giao dịch khi phát sinh lỗi xung đột mức engine.
        } finally {
            $this->manualSqliteTransactionActive = false;
        }
    }
}
