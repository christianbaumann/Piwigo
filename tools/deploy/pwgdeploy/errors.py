"""One exception type per distinct failure mode.

The CLI maps each to its own exit code, so a caller can tell a bad credential file
from a refused FTPS handshake without parsing a message.
"""


class DeployError(Exception):
    """Base for every failure this tool reports. Carries the process exit code."""

    exit_code = 1


class ConfigError(DeployError):
    """The credential JSON is malformed, incomplete or holds a value the remote would reject."""

    exit_code = 3


class GitError(DeployError):
    """`git ls-files` failed, or reported nothing at all."""

    exit_code = 4


class TransportError(DeployError):
    """Connect, authenticate or transfer failed."""

    exit_code = 5


class InsecureTransportError(TransportError):
    """The server advertises no AUTH TLS, so logging in would send the password in clear."""

    exit_code = 6


class RemoteHttpError(DeployError):
    """A remote request returned a non-2xx status or a body that makes no sense here."""

    exit_code = 7


class InstallError(RemoteHttpError):
    """install.php re-rendered its form instead of installing; it carries the field errors."""

    exit_code = 8


class StateMismatchError(DeployError):
    """The local manifest and the remote disagree on whether the gallery is installed."""

    exit_code = 9


class VersionError(DeployError):
    """The checkout's PHPWG_VERSION cannot be read, or differs from the remote's.

    Both failure modes share one code deliberately: they are one question — "which core
    is this?" — and a caller branching on the answer takes the same action either way.
    """

    exit_code = 10


class BackupError(DeployError):
    """A backup run could not be taken or stored: a short download, a refused dump, a bad answer."""

    exit_code = 11


class UnsafeRemotePathError(BackupError):
    """The temporary script listed a path that is not under a backed-up folder, or climbs out of it."""

    exit_code = 12


class AgentLeftoverError(BackupError):
    """The temporary script could not be removed from the host; it is still there and must be deleted by hand."""

    exit_code = 13


class PredeployBackupError(DeployError):
    """The snapshot a deploy takes first failed, so nothing was deployed.

    Wraps every failure of that backup except the two that keep their own code (12, 13): an unsafe
    listed path and a leftover script need a different reaction than "retry or use --no-backup".
    """

    exit_code = 14


class RestoreError(DeployError):
    """A restore was refused, or failed after it started; wraps everything but 12 and 13."""

    exit_code = 15
