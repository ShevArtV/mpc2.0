/**
 * mpcVisualEditor — блокировка ресурса на время редактирования (чтобы двое не
 * правили один ресурс одновременно).
 *
 * Захват при входе в edit-mode (lock/acquire). Пока идёт правка — heartbeat
 * продлевает лок (refresh TTL). Снятие: явный выход / закрытие вкладки (beacon)
 * + авто-выход по бездействию (нет активности > TTL → лок протух → завершаем
 * режим). «Активность» — открытие любого редактора (markActivity).
 */
import { S, setCookie } from './state.js';
import { api } from './api.js';
import { toast } from './dom.js';

var heartbeatTimer = null;
var lastActivity = 0;
var idleMs = 600000;

function now() { return Date.now(); }

export function markActivity() { lastActivity = now(); }

export function acquireLock() {
    return api.post('lock/acquire', { resourceId: S.cfg.resourceId || 0 })
        .then(function (r) { return (r && r.success && r.data) ? r.data : { mine: true, ttl: 600 }; })
        .catch(function () { return { mine: true, ttl: 600 }; }); // сеть упала — не блокируем правку
}

function releaseLock() {
    return api.post('lock/release', { resourceId: S.cfg.resourceId || 0 }).catch(function () {});
}

// Надёжное снятие при уходе/закрытии вкладки — sendBeacon (переживает unload).
function beaconRelease() {
    if (!navigator.sendBeacon) { return; }
    var body = new FormData();
    body.append('action', 'lock/release');
    body.append('resourceId', S.cfg.resourceId || 0);
    navigator.sendBeacon(S.cfg.connectorUrl, body);
}

// Жизненный цикл лока ПОСЛЕ успешного захвата (mine=true).
export function startLockLifecycle(ttlSec) {
    idleMs = (ttlSec > 0 ? ttlSec : 600) * 1000;
    var beatMs = Math.max(60000, Math.floor(idleMs / 3)); // 3 биения за TTL
    markActivity();
    heartbeatTimer = setInterval(tick, beatMs);
    window.addEventListener('pagehide', beaconRelease);
}

function tick() {
    if (now() - lastActivity > idleMs) {
        endEditing('Редактирование завершено: нет активности');
        return;
    }
    acquireLock().then(function (lock) {
        if (lock && lock.mine === false) {
            endEditing('Блокировку перехватил: ' + (lock.by || 'другой пользователь'));
        }
    });
}

function endEditing(msg) {
    if (heartbeatTimer) { clearInterval(heartbeatTimer); heartbeatTimer = null; }
    window.removeEventListener('pagehide', beaconRelease);
    releaseLock();
    toast(msg, true);
    setCookie('mpcve_editing', '0');
    setTimeout(function () { window.location.reload(); }, 1600);
}

// Явный выход (тумблер «Завершить») — снять лок до перезагрузки.
export function releaseOnExit() {
    if (heartbeatTimer) { clearInterval(heartbeatTimer); heartbeatTimer = null; }
    window.removeEventListener('pagehide', beaconRelease);
    beaconRelease(); // надёжно даже при немедленном reload
}

// Баннер «занято другим» (правка не включается).
export function showLockBanner(by) {
    var b = document.createElement('div');
    b.className = 'mpcve-lockbanner';
    b.textContent = '🔒 Ресурс редактирует: ' + by + '. Редактирование заблокировано — попробуйте позже.';
    document.body.appendChild(b);
}
