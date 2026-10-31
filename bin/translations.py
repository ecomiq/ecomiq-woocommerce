import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
DOMAIN = "ecomiq-for-woocommerce"
SOURCE = ROOT / "languages" / f"{DOMAIN}-es_ES.po"
LOCALES = [
    "es_ES", "es_MX", "es_PE", "es_CO", "es_CL", "es_AR", "es_EC",
    "es_UY", "es_VE", "es_CR", "es_GT", "es_DO", "es_PR",
]
ENTRY = re.compile(r'^msgid "(.*)"\nmsgstr "(.*)"$', re.MULTILINE)
PLURAL = re.compile(r'^msgid "(.*)"\nmsgid_plural "(.*)"\nmsgstr\[0\] "(.*)"\nmsgstr\[1\] "(.*)"$', re.MULTILINE)
STRING = r"'((?:[^'\\]|\\.)*)'"


def unquote(value):
    return value.replace('\\"', '"').replace("\\n", "\n").replace("\\\\", "\\")


def php(value):
    return ' . "\\0" . '.join("'" + part.replace("\\", "\\\\").replace("'", "\\'") + "'" for part in value.split("\0"))


def sources():
    used = set()
    for path in [ROOT / f"{DOMAIN}.php", *sorted((ROOT / "includes").glob("*.php"))]:
        text = path.read_text(encoding="utf-8")
        used.update(re.findall(r"""__\(\s*'((?:[^'\\]|\\.)*)'\s*,\s*'""" + DOMAIN, text))
        used.update(re.findall(r"""esc_html__\(\s*'((?:[^'\\]|\\.)*)'\s*,\s*'""" + DOMAIN, text))
        for singular, plural in re.findall(r"_n\(\s*" + STRING + r"\s*,\s*" + STRING + r"\s*,[^;]*?'" + DOMAIN, text):
            used.add(singular + "\0" + plural)
        for header in ("Description",):
            match = re.search(rf"^\s*\*\s*{header}:\s*(.+)$", text, re.MULTILINE)
            if match:
                used.add(match.group(1).strip())
    return {item.replace("\\'", "'") for item in used}


def main():
    text = SOURCE.read_text(encoding="utf-8")
    messages = {
        unquote(source): unquote(target)
        for source, target in ENTRY.findall(text)
        if source
    }
    for singular, plural, one, other in PLURAL.findall(text):
        messages[unquote(singular) + "\0" + unquote(plural)] = unquote(one) + "\0" + unquote(other)
    missing = sorted(sources() - messages.keys())
    if missing:
        print("Missing translations:", *missing, sep="\n  ")
        return 1
    body = ",".join(f"{php(source)}=>{php(target)}" for source, target in messages.items())
    for locale in LOCALES:
        target = ROOT / "languages" / f"{DOMAIN}-{locale}.l10n.php"
        target.write_text(
            "<?php\nreturn ["
            f"'domain'=>{php(DOMAIN)},"
            "'plural-forms'=>'nplurals=2; plural=n != 1;',"
            f"'language'=>{php(locale)},"
            "'project-id-version'=>'Ecomiq for WooCommerce',"
            f"'messages'=>[{body}]];\n",
            encoding="utf-8",
        )
    print(f"{len(messages)} strings, {len(LOCALES)} locales")
    return 0


if __name__ == "__main__":
    sys.exit(main())
