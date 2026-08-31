"""`python3 -m pwgdeploy` — the same entry point as the installed `pwg-deploy` script."""

from pwgdeploy.cli import main

if __name__ == "__main__":
    raise SystemExit(main())
