document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.alert').forEach(function (alert) {
        window.setTimeout(function () {
            if (document.body.contains(alert)) {
                const alertInstance = bootstrap.Alert.getOrCreateInstance(alert);
                alertInstance.close();
            }
        }, 3000);
    });
});
