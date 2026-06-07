# Манифесты mpc CLI

Базовая папка манифестов по умолчанию (настройка `mpc_manifests_path`).
Кладите сюда боевые проектные манифесты — тогда путь можно не передавать:

```
./console/mpc settings apply          # → settings.php из этой папки
./console/mpc resources apply         # → resources.php
./console/mpc settings apply prod     # → prod.php (профиль/окружение)
```

Шаблоны для копирования — в `../examples/`. База переопределяется системной
настройкой `mpc_manifests_path` или переменной окружения `MPC_MANIFESTS_PATH`
(относительный путь — от папки `core/` MODX).
