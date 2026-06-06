<?php

namespace MpcServices\Cli\Apply;

/**
 * Применение списка пакетов из манифеста. Запись:
 *   ['name'=>'pdoTools', 'version'=>'2.0']  — установка из провайдера (по имени),
 *   ['file'=>'/abs/x.transport.zip']        — установка из локального transport-zip,
 *   ['remove'=>'packageName']               — удаление установленного пакета.
 * Идемпотентно: уже установленный пакет (>= версии) пропускаем. Деструктив
 * (install/remove реально меняют БД) выполняется только при $force=true.
 *
 * Паттерн установки из провайдера — как резолверы зависимостей ядра/miniShop2:
 * modTransportProvider->request → скачать zip → modTransportPackage->install().
 */
class PackagesApply
{
    private \modX $modx;

    public function __construct(\modX $modx)
    {
        $this->modx = $modx;
    }

    public function apply(array $manifest, bool $dryRun = false, bool $force = false): array
    {
        $plan = [];
        $errors = [];

        foreach ($manifest as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (!empty($entry['remove'])) {
                $this->planRemove((string)$entry['remove'], $dryRun, $force, $plan, $errors);
            } elseif (!empty($entry['file'])) {
                $this->planInstallFile((string)$entry['file'], $dryRun, $force, $plan, $errors);
            } elseif (!empty($entry['name'])) {
                $this->planInstallProvider($entry, $dryRun, $force, $plan, $errors);
            }
        }

        $msg = ($dryRun ? 'План пакетов: ' : 'Пакеты: ') . $this->summary($plan);
        if (!$dryRun && !$force && $this->hasDestructive($plan)) {
            $msg .= ' — для выполнения нужен --force';
        }
        if ($errors) {
            $msg .= '. Ошибки: ' . implode('; ', $errors);
        }
        return ['success' => empty($errors), 'message' => $msg, 'data' => ['plan' => $plan, 'errors' => $errors]];
    }

    private function installedPackage(string $name)
    {
        return $this->modx->getObject('transport.modTransportPackage', [
            'package_name' => $name,
            'installed:!=' => null,
        ]) ?: $this->modx->getObject('transport.modTransportPackage', ['package_name' => $name]);
    }

    private function planRemove(string $name, bool $dryRun, bool $force, array &$plan, array &$errors): void
    {
        $pkg = $this->installedPackage($name);
        if (!$pkg || !$pkg->get('installed')) {
            $plan[] = ['action' => 'skip', 'ref' => $name . ' (не установлен)'];
            return;
        }
        $plan[] = ['action' => 'remove', 'ref' => $name];
        if ($dryRun || !$force) {
            return;
        }
        if (!$pkg->uninstall()) {
            $errors[] = 'Не удалось удалить ' . $name;
            return;
        }
        $pkg->remove();
    }

    private function planInstallFile(string $file, bool $dryRun, bool $force, array &$plan, array &$errors): void
    {
        if (!is_file($file) || !preg_match('/\.transport\.zip$/', $file)) {
            $errors[] = 'Не transport.zip: ' . $file;
            return;
        }
        $signature = basename($file, '.transport.zip');
        if ($this->isInstalled($signature)) {
            $plan[] = ['action' => 'skip', 'ref' => $signature . ' (уже установлен)'];
            return;
        }
        $plan[] = ['action' => 'install', 'ref' => $signature . ' (файл)'];
        if ($dryRun || !$force) {
            return;
        }
        $packagesDir = $this->modx->getOption('core_path') . 'packages/';
        $dest = $packagesDir . basename($file);
        if (realpath($file) !== realpath($dest)) {
            copy($file, $dest);
        }
        $package = $this->modx->newObject('transport.modTransportPackage');
        $package->set('signature', $signature);
        $package->set('state', 1);
        $package->set('workspace', 1);
        $this->fillVersionFromSignature($package, $signature);
        if (!$package->save() || !$package->install()) {
            $errors[] = 'Установка из файла не удалась: ' . $signature;
        }
    }

    private function planInstallProvider(array $entry, bool $dryRun, bool $force, array &$plan, array &$errors): void
    {
        $name = (string)$entry['name'];
        if ($this->isInstalled($name)) {
            $plan[] = ['action' => 'skip', 'ref' => $name . ' (уже установлен)'];
            return;
        }
        $plan[] = ['action' => 'install', 'ref' => $name . ' (провайдер)'];
        if ($dryRun || !$force) {
            return;
        }

        $provider = $this->modx->getObject('transport.modTransportProvider', 1);
        if (!$provider) {
            $errors[] = 'Провайдер пакетов (id=1) не найден';
            return;
        }
        $this->modx->getVersionData();
        $product = $this->modx->version['code_name'] ?? '';
        $response = $provider->request('package', 'GET', ['supports' => $product, 'query' => $name]);
        if (!$response || $response->isError()) {
            $errors[] = 'Провайдер не ответил по пакету ' . $name;
            return;
        }
        $foundSignature = '';
        $location = '';
        $xml = @simplexml_load_string($response->response);
        foreach (($xml->package ?? []) as $p) {
            if (preg_match('#^' . preg_quote($name, '#') . '\b#i', (string)$p->name)) {
                $foundSignature = (string)$p->signature;
                $location = (string)$p->location;
                break;
            }
        }
        if ($foundSignature === '' || $location === '') {
            $errors[] = 'Пакет не найден у провайдера: ' . $name;
            return;
        }

        $target = $this->modx->getOption('core_path') . 'packages/' . $foundSignature . '.transport.zip';
        $bin = @file_get_contents($location);
        if ($bin === false) {
            $errors[] = 'Не удалось скачать ' . $name . ' (' . $location . ')';
            return;
        }
        file_put_contents($target, $bin);

        $package = $this->modx->newObject('transport.modTransportPackage');
        $package->set('signature', $foundSignature);
        $package->set('state', 1);
        $package->set('workspace', 1);
        $package->set('provider', $provider->get('id'));
        $this->fillVersionFromSignature($package, $foundSignature);
        if (!$package->save() || !$package->install()) {
            $errors[] = 'Установка из провайдера не удалась: ' . $foundSignature;
        }
    }

    private function isInstalled(string $signatureOrName): bool
    {
        // по сигнатуре
        $bySig = $this->modx->getObject('transport.modTransportPackage', ['signature' => $signatureOrName, 'installed:!=' => null]);
        if ($bySig) {
            return true;
        }
        $base = preg_replace('/-[0-9].*$/', '', $signatureOrName);
        $byName = $this->modx->getObject('transport.modTransportPackage', ['package_name' => $base, 'installed:!=' => null]);
        return (bool)$byName;
    }

    private function fillVersionFromSignature($package, string $signature): void
    {
        // signature: name-1.2.3-pl → version 1.2.3, release pl
        if (preg_match('/^(.+?)-([0-9]+)\.([0-9]+)\.([0-9]+)-?(.*)$/', $signature, $m)) {
            $package->set('package_name', $m[1]);
            $package->set('version_major', (int)$m[2]);
            $package->set('version_minor', (int)$m[3]);
            $package->set('version_patch', (int)$m[4]);
            if ($m[5] !== '') {
                $package->set('release', $m[5]);
            }
        } else {
            $package->set('package_name', $signature);
        }
    }

    private function hasDestructive(array $plan): bool
    {
        foreach ($plan as $a) {
            if (in_array($a['action'], ['install', 'remove'], true)) {
                return true;
            }
        }
        return false;
    }

    private function summary(array $plan): string
    {
        $c = ['install' => 0, 'remove' => 0, 'skip' => 0];
        foreach ($plan as $a) {
            if (isset($c[$a['action']])) {
                $c[$a['action']]++;
            }
        }
        return sprintf('установить %d, удалить %d, пропустить %d', $c['install'], $c['remove'], $c['skip']);
    }
}
