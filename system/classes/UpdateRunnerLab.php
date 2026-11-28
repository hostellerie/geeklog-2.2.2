<?php
namespace Geeklog;

require_once __DIR__ . '/UpdateCatalogStateLab.php';
require_once __DIR__ . '/UpdateDownloadLab.php';
require_once __DIR__ . '/UpdateMaintenanceLab.php';

/** Full code-only update orchestration, restricted to a disposable CLI lab. */
final class UpdateRunnerLab
{
    public static function install($root, $json, $signature, $key, $host, $now, $id, UpdateSchemaLab $probe)
    {
        $catalog = UpdateCatalogStateLab::accept($root, $json, $signature, $key, $host, $now);
        $root = realpath($root);
        $code = UpdateSiteVersionLab::read($root);
        $schema = $probe->read();
        $history = UpdateHistoryLab::applied(UpdateHistoryLab::read($root));
        $database = $probe->driver();
        $selected = null;
        foreach ($catalog->applicable($code, $schema, PHP_VERSION, $database, $history) as $update) {
            if ($update['id'] === $id) {
                $selected = $update;
                break;
            }
        }
        if ($selected === null || $selected['schema'] !== $schema) {
            throw new \RuntimeException('No compatible code-only update');
        }
        $archive = UpdateDownloadLab::stage($root, $catalog, $id, $code, $schema, PHP_VERSION, $database, $history);
        // Keep archive for diagnosis/recovery; never remove it on failure.
        $result = UpdateMaintenanceLab::run($root, $archive, $selected, $code, $schema, null, null, $probe);
        if ($result['status'] !== 'committed') {
            return $result;
        }
        return UpdateMaintenanceLab::finalize($root, $archive, $selected, $probe);
    }
}
