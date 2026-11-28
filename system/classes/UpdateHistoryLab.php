<?php
namespace Geeklog;

/** Local laboratory history. Caller holds the exclusive maintenance lease. */
final class UpdateHistoryLab
{
    private static function id($value)
    {
        return is_string($value) && preg_match('/^[a-z0-9][a-z0-9._-]{0,79}$/D', $value) === 1;
    }

    public static function read($root)
    {
        $path = $root . '/.gl-lab-history.json';
        clearstatcache();
        if (!file_exists($path) && !is_link($path)) {
            return array();
        }
        $stat = @stat($path);
        if (is_link($path) || !is_file($path) || $stat === false || $stat['nlink'] !== 1 ||
            $stat['size'] > 1048576) {
            throw new \RuntimeException('Invalid update history');
        }
        $bytes = file_get_contents($path);
        $records = json_decode($bytes, true);
        if (!is_array($records) || count($records) > 1000 || json_encode($records) !== $bytes) {
            throw new \RuntimeException('Malformed update history');
        }
        $ids = array();
        $index = 0;
        foreach ($records as $key => $record) {
            if ($key !== $index++ || !is_array($record) || count($record) !== 4 ||
                !isset($record['id'], $record['sha256'], $record['transaction'], $record['supersedes']) ||
                !self::id($record['id']) || in_array($record['id'], $ids, true) ||
                !is_string($record['sha256']) || preg_match('/^[a-f0-9]{64}$/D', $record['sha256']) !== 1 ||
                !is_string($record['transaction']) || preg_match('/^[a-f0-9]{32}$/D', $record['transaction']) !== 1 ||
                !is_array($record['supersedes']) || count($record['supersedes']) > 1000) {
                throw new \RuntimeException('Malformed update receipt');
            }
            $ids[] = $record['id'];
            foreach ($record['supersedes'] as $id) {
                if (!self::id($id)) {
                    throw new \RuntimeException('Invalid superseded identifier');
                }
                $ids[] = $id;
            }
        }
        return $records;
    }

    public static function applied(array $history)
    {
        $ids = array();
        foreach ($history as $record) {
            $ids[] = $record['id'];
            $ids = array_merge($ids, $record['supersedes']);
        }
        return array_values(array_unique($ids));
    }

    public static function validateEligibility(array $history, array $update)
    {
        $ids = self::applied($history);
        if (in_array($update['id'], $ids, true) || array_diff($update['requires'], $ids) ||
            array_intersect($update['conflicts'], $ids)) {
            throw new \RuntimeException('Update already applied, dependency missing or conflict');
        }
    }

    public static function digest(array $history)
    {
        return hash('sha256', json_encode($history));
    }

    public static function record($root, array $update, $transaction, $before)
    {
        $history = self::read($root);
        $receipt = array('id' => $update['id'], 'sha256' => $update['package']['sha256'],
            'transaction' => $transaction, 'supersedes' => $update['supersedes']);
        if (count($history) > 0 && end($history) === $receipt) {
            $previous = $history;
            array_pop($previous);
            if (self::digest($previous) === $before) {
                return $history; // Resume after receipt publication.
            }
        }
        if (self::digest($history) !== $before || count($history) >= 1000) {
            throw new \RuntimeException('Update history changed');
        }
        self::validateEligibility($history, $update);
        $next = $history;
        $next[] = $receipt;
        $bytes = json_encode($next);
        if (!is_string($bytes) || strlen($bytes) > 1048576) {
            throw new \RuntimeException('Update history exceeds limit');
        }
        $path = $root . '/.gl-lab-history.json';
        $random = openssl_random_pseudo_bytes(16, $strong);
        if ($random === false || !$strong) {
            throw new \RuntimeException('Cannot stage update receipt');
        }
        $tmp = $path . '.' . bin2hex($random);
        $handle = @fopen($tmp, 'xb');
        if ($handle === false) {
            throw new \RuntimeException('Cannot stage update history');
        }
        try {
            try {
                if (!chmod($tmp, 0600) || fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle)) {
                    throw new \RuntimeException('Cannot write update history');
                }
            } finally {
                fclose($handle);
            }
            if (self::read($root) !== $history || file_get_contents($tmp) !== $bytes ||
                !rename($tmp, $path) || self::read($root) !== $next) {
                throw new \RuntimeException('Cannot publish update history');
            }
        } finally {
            if (is_file($tmp) && !is_link($tmp)) {
                @unlink($tmp);
            }
        }
        return $next;
    }
}
