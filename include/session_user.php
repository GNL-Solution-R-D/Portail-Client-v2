// include/session_user.php
function sessionUserNamespace(): string {
    $u = $_SESSION['user'] ?? [];
    return (string)($u['k8s_namespace'] ?? $u['k8sNamespace'] ?? $u['namespace_k8s']
        ?? $u['k8s_ns'] ?? $u['namespace'] ?? '');
}
function sessionUserId(): int {
    return (int)(($_SESSION['user'] ?? [])['id'] ?? 0);
}