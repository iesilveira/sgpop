#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Atualizador/empacotador do plugin sgpop — versão de destino 1.0.

Python 3.8+; usa somente a biblioteca padrão.
Este corretivo não reescreve arquivos do plugin: valida a versão local e cria
o ZIP completo no diretório pai. O próprio script e o lançador .bat ficam fora
do pacote.
"""

import os
import re
import shutil
import subprocess
import sys
import tempfile
import zipfile
from pathlib import Path

TARGET_VERSION = "1.0"
EXPECTED_PROJECT_NAME = "sgpop"

SCRIPT_PATH = Path(__file__).resolve()
PROJECT_DIR = SCRIPT_PATH.parent
OUTPUT_DIR = PROJECT_DIR.parent
ZIP_PATH = OUTPUT_DIR / (EXPECTED_PROJECT_NAME + "-" + TARGET_VERSION + ".zip")

REQUIRED_FILES = (
    PROJECT_DIR / "sgpop.php",
    PROJECT_DIR / "readme.txt",
    PROJECT_DIR / "CHANGELOG.md",
)

EXCLUDED_DIRS = {
    ".git", ".github", ".idea", ".vscode", "__pycache__",
}
EXCLUDED_NAMES = {
    ".DS_Store", "Thumbs.db", "desktop.ini",
}
EXCLUDED_SUFFIXES = {
    ".pyc", ".pyo", ".zip", ".bat",
}


class UpdateError(RuntimeError):
    """Falha controlada durante a validação ou empacotação."""


def log(message):
    print(message)


def read_utf8(path):
    # utf-8-sig também aceita arquivos UTF-8 sem BOM.
    return path.read_text(encoding="utf-8-sig")


def validate_project():
    if PROJECT_DIR.name.lower() != EXPECTED_PROJECT_NAME:
        raise UpdateError(
            "A pasta do projeto deve se chamar 'sgpop'. "
            "Pasta encontrada: " + str(PROJECT_DIR.name)
        )
    if OUTPUT_DIR.resolve() == PROJECT_DIR.resolve():
        raise UpdateError("O ZIP precisa ser criado no diretório pai do projeto.")

    missing = [str(path.name) for path in REQUIRED_FILES if not path.is_file()]
    if missing:
        raise UpdateError("Arquivos obrigatórios ausentes: " + ", ".join(missing))

    php_text = read_utf8(REQUIRED_FILES[0])
    readme_text = read_utf8(REQUIRED_FILES[1])
    changelog_text = read_utf8(REQUIRED_FILES[2])

    header_match = re.search(
        r"(?mi)^\s*\*\s*Version:\s*([0-9]+(?:\.[0-9]+)*)\s*$",
        php_text,
    )
    constant_match = re.search(
        r"""define\s*\(\s*['"]SGPOP_VERSION['"]\s*,\s*['"]([^'"]+)['"]\s*\)""",
        php_text,
    )
    stable_match = re.search(
        r"(?mi)^\s*Stable tag:\s*([0-9]+(?:\.[0-9]+)*)\s*$",
        readme_text,
    )
    if not header_match:
        raise UpdateError("Não encontrei o cabeçalho Version em sgpop.php.")
    if not constant_match:
        raise UpdateError("Não encontrei a constante SGPOP_VERSION em sgpop.php.")
    if not stable_match:
        raise UpdateError("Não encontrei Stable tag em readme.txt.")

    versions = {
        "cabeçalho PHP": header_match.group(1),
        "constante PHP": constant_match.group(1),
        "Stable tag": stable_match.group(1),
    }
    inconsistent = [
        label + "=" + version
        for label, version in versions.items()
        if version != TARGET_VERSION
    ]
    if inconsistent:
        raise UpdateError(
            "A versão local não corresponde à versão de destino "
            + TARGET_VERSION
            + ": "
            + ", ".join(inconsistent)
        )

    if not re.search(
        r"(?m)^\s*=\s*" + re.escape(TARGET_VERSION) + r"\s*=",
        changelog_text,
    ):
        raise UpdateError(
            "O changelog não contém uma entrada para a versão " + TARGET_VERSION + "."
        )

    if SCRIPT_PATH.parent.resolve() != PROJECT_DIR.resolve():
        raise UpdateError("Coloque atualizar_sgpop.py dentro da pasta sgpop.")

    return versions


def should_exclude(path):
    relative = path.relative_to(PROJECT_DIR)
    if path.resolve() == SCRIPT_PATH:
        return True
    if any(part in EXCLUDED_DIRS for part in relative.parts):
        return True
    if path.name in EXCLUDED_NAMES:
        return True
    if path.suffix.lower() in EXCLUDED_SUFFIXES:
        return True
    if path.name.lower() == "atualizador.bat":
        return True
    if path.name.lower().startswith(".env"):
        return True
    return False


def validate_php_if_available():
    php_executable = shutil.which("php")
    if not php_executable:
        log("[AVISO] PHP CLI não encontrado; php -l foi ignorado.")
        return
    result = subprocess.run(
        [php_executable, "-l", str(REQUIRED_FILES[0])],
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        text=True,
        check=False,
    )
    if result.returncode != 0:
        raise UpdateError("php -l falhou:\n" + result.stdout.strip())
    log("[OK] Sintaxe PHP validada com php -l.")


def create_verified_zip():
    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
    fd, temporary_name = tempfile.mkstemp(
        prefix=".sgpop-", suffix=".zip.tmp", dir=str(OUTPUT_DIR)
    )
    os.close(fd)
    temporary_zip = Path(temporary_name)

    try:
        included = []
        with zipfile.ZipFile(
            str(temporary_zip),
            mode="w",
            compression=zipfile.ZIP_DEFLATED,
            compresslevel=9,
        ) as archive:
            for path in sorted(PROJECT_DIR.rglob("*")):
                if path.is_symlink():
                    raise UpdateError(
                        "Link simbólico encontrado; empacotamento cancelado: "
                        + str(path.relative_to(PROJECT_DIR))
                    )
                if not path.is_file() or should_exclude(path):
                    continue
                relative = path.relative_to(PROJECT_DIR)
                archive_name = (Path(EXPECTED_PROJECT_NAME) / relative).as_posix()
                archive.write(str(path), archive_name)
                included.append(archive_name)

        if not included:
            raise UpdateError("Nenhum arquivo foi incluído no ZIP.")

        with zipfile.ZipFile(str(temporary_zip), "r") as archive:
            bad_member = archive.testzip()
            if bad_member:
                raise UpdateError("Arquivo inválido dentro do ZIP: " + bad_member)

            names = set(archive.namelist())
            required_members = {
                "sgpop/sgpop.php",
                "sgpop/readme.txt",
                "sgpop/CHANGELOG.md",
            }
            missing = sorted(required_members - names)
            if missing:
                raise UpdateError(
                    "O ZIP não contém os arquivos obrigatórios: "
                    + ", ".join(missing)
                )
            if any(name.endswith("atualizar_sgpop.py") for name in names):
                raise UpdateError("O atualizador foi incluído no ZIP por engano.")
            if any(name.lower().endswith(".bat") for name in names):
                raise UpdateError("Um lançador .bat foi incluído no ZIP por engano.")

        # Só substitui o ZIP final depois que o novo arquivo foi verificado.
        os.replace(str(temporary_zip), str(ZIP_PATH))
        return len(included)
    except Exception:
        try:
            if temporary_zip.exists():
                temporary_zip.unlink()
        except OSError:
            pass
        raise


def main():
    print("=" * 60)
    print("Atualizador — versão de destino " + TARGET_VERSION)
    print("Projeto: " + str(PROJECT_DIR))
    print("Backup automático: desativado")
    print("=" * 60)

    try:
        log("Validando estrutura e versão...")
        versions = validate_project()
        log("[OK] Versão local confirmada: " + versions["cabeçalho PHP"])
        log("[OK] Nenhum arquivo-fonte será sobrescrito ou excluído.")

        validate_php_if_available()

        log("Gerando e verificando o ZIP...")
        file_count = create_verified_zip()

        log("[OK] Arquivos incluídos: " + str(file_count))
        log("Atualização concluída.")
        log("Versão empacotada: " + TARGET_VERSION)
        log("Pacote criado: " + str(ZIP_PATH))
        log("O ZIP foi salvo fora da pasta do projeto.")
        return 0
    except KeyboardInterrupt:
        log("\n[ERRO] Operação cancelada pelo usuário.")
        return 130
    except (UpdateError, OSError, zipfile.BadZipFile) as exc:
        log("[ERRO] " + str(exc))
        return 1


if __name__ == "__main__":
    sys.exit(main())
