"""Renders WoW spell description templates ("Inflige $s1 points de dégâts en $d.") into plain text.

Supports effect values ($s1 $m1 $o1 $t1 $a1 $x1 $e1 $b1 $q1), spell values ($d $r $h $n $u),
references to other spells ($12345s1, $@spelldesc12345, $@spellname12345), expressions (${$m1/10}),
divisions ($/1000;S1), plural/gender switches ($lpoint:points;), conditionals ($?s123[a][b], the
default branch is used) and the |n |c |r |T escapes. Character stats ($AP, $SP...) count as 0.
"""
import math
import re
import warnings

warnings.filterwarnings('ignore', category=SyntaxWarning)  # odd expressions in client texts are evaluated defensively


class SpellData:
    """Everything a description can refer to, keyed by spell ID (filled by tooltips.py)."""

    def __init__(self):
        self.names = {}          # id -> {'en','fr'}
        self.texts = {}          # id -> {'en': {'desc','aura'}, 'fr': {...}}
        self.effects = {}        # id -> {index: effect row}
        self.misc = {}           # id -> SpellMisc row
        self.durations = {}      # SpellDuration ID -> row
        self.ranges = {}         # SpellRange ID -> row
        self.radius = {}         # SpellRadius ID -> row
        self.aura_options = {}   # id -> SpellAuraOptions row
        self.targets = {}        # id -> SpellTargetRestrictions row


UNITS = {
    'en': {'sec': '{} sec', 'min': '{} min', 'hr': '{} hr', 'day': '{} days', 'inf': 'until cancelled'},
    'fr': {'sec': '{} s', 'min': '{} min', 'hr': '{} h', 'day': '{} j', 'inf': 'jusqu’à annulation'},
}


def number(value, locale):
    if value is None:
        return '0'
    if abs(value - round(value)) < 0.05:
        return str(int(round(value)))
    text = f'{value:.1f}'
    return text.replace('.', ',') if locale == 'fr' else text


def duration_text(ms, locale):
    units = UNITS[locale]
    if ms is None:
        return number(0, locale)
    if ms < 0:
        return units['inf']
    seconds = ms / 1000
    if seconds < 60:
        return units['sec'].format(number(seconds, locale))
    if seconds < 3600:
        return units['min'].format(number(seconds / 60, locale))
    if seconds < 86400 * 2:
        return units['hr'].format(number(seconds / 3600, locale))
    return units['day'].format(number(seconds / 86400, locale))


class Renderer:
    def __init__(self, data: SpellData, locale: str):
        self.d = data
        self.locale = locale

    # ---------- values ----------
    def _effect(self, spell, index):
        return self.d.effects.get(spell, {}).get(max(0, index - 1))

    def _duration_ms(self, spell):
        misc = self.d.misc.get(spell)
        row = self.d.durations.get(misc['DurationIndex']) if misc else None
        return row['Duration'] if row else 0

    def value(self, spell, letter, index):
        """Numeric value of a token, or None when unknown."""
        low = letter.lower()
        if low in ('s', 'm', 'w'):
            effect = self._effect(spell, index or 1)
            return abs(effect['EffectBasePoints']) if effect else 0
        if low == 'o':
            effect = self._effect(spell, index or 1)
            if not effect:
                return 0
            period = effect['EffectAuraPeriod']
            duration = self._duration_ms(spell)
            ticks = duration / period if period and duration > 0 else 1
            return abs(effect['EffectBasePoints']) * ticks
        if low == 't':
            effect = self._effect(spell, index or 1)
            return (effect['EffectAuraPeriod'] / 1000) if effect else 0
        if low == 'a':
            effect = self._effect(spell, index or 1)
            row = self.d.radius.get(effect['EffectRadiusIndex1']) if effect else None
            return row['Radius'] if row else 0
        if low == 'x':
            effect = self._effect(spell, index or 1)
            return effect['EffectChainTargets'] if effect else 0
        if low == 'e':
            effect = self._effect(spell, index or 1)
            return effect['EffectAmplitude'] if effect else 0
        if low == 'f':
            effect = self._effect(spell, index or 1)
            return effect['EffectChainAmplitude'] if effect else 0
        if low == 'b':
            effect = self._effect(spell, index or 1)
            return effect['EffectPointsPerResource'] if effect else 0
        if low == 'q':
            effect = self._effect(spell, index or 1)
            return effect['EffectMiscValue1'] if effect else 0
        if low == 'd':
            return self._duration_ms(spell) / 1000
        if low == 'r':
            misc = self.d.misc.get(spell)
            row = self.d.ranges.get(misc['RangeIndex']) if misc else None
            return row['RangeMax1'] if row else 0
        if low in ('h', 'n', 'u'):
            options = self.d.aura_options.get(spell)
            if not options:
                return 0
            return {'h': options['ProcChance'], 'n': options['ProcCharges'], 'u': options['CumulativeAura']}[low]
        if low == 'i':
            targets = self.d.targets.get(spell)
            return targets['MaxTargets'] if targets else 0
        if low == 'v':
            targets = self.d.targets.get(spell)
            return targets['MaxTargetLevel'] if targets else 0
        return None

    def token_text(self, spell, letter, index):
        if letter.lower() == 'd':
            return duration_text(self._duration_ms(spell), self.locale)
        if letter == 'z':
            return 'votre foyer' if self.locale == 'fr' else 'your home'
        value = self.value(spell, letter, index)
        return number(value, self.locale) if value is not None else ''

    # ---------- parsing ----------
    def render(self, text, spell, depth=0):
        if not text:
            return ''
        out, i, last_number = [], 0, None
        n = len(text)
        while i < n:
            ch = text[i]
            if ch == '|' and i + 1 < n:
                code = text[i + 1]
                if code == 'n':
                    out.append('\n'); i += 2; continue
                if code in 'cC' and i + 10 <= n:
                    i += 10; continue
                if code in 'rR':
                    i += 2; continue
                if code in 'TtAa':
                    end = text.find('|t', i + 2) if code in 'Tt' else text.find('|a', i + 2)
                    i = end + 2 if end >= 0 else n
                    continue
                if code == '4':  # |4singular:plural;
                    end = text.find(';', i)
                    if end > 0:
                        forms = text[i + 2:end].split(':')
                        out.append(forms[-1] if last_number is None or last_number != 1 else forms[0])
                        i = end + 1
                        continue
            if ch != '$' or i + 1 >= n:
                out.append(ch); i += 1; continue
            i += 1
            c = text[i]
            if c == '{':
                end = self._match(text, i, '{', '}')
                expression = text[i + 1:end]
                value = self._evaluate(expression, spell, depth)
                i = end + 1
                decimals = None
                m = re.match(r'\.(\d)', text[i:])
                if m:
                    decimals = int(m.group(1)); i += 2
                if value is None:
                    out.append('?')
                else:
                    last_number = value
                    if decimals is not None:
                        formatted = f'{value:.{decimals}f}'
                        out.append(formatted.replace('.', ',') if self.locale == 'fr' else formatted)
                    else:
                        out.append(number(value, self.locale))
                continue
            if c == '?':
                i, branch = self._conditional(text, i + 1)
                out.append(self.render(branch, spell, depth + 1))
                continue
            if c == '@':
                m = re.match(r'@(spelldesc|spellname|spellaura|spelltooltip|spellicon)(\d+)', text[i:])
                if m:
                    target = int(m.group(2))
                    if m.group(1) == 'spellname':
                        out.append((self.d.names.get(target) or {}).get(self.locale, ''))
                    elif m.group(1) in ('spelldesc', 'spelltooltip', 'spellaura') and depth < 3:
                        key = 'aura' if m.group(1) == 'spellaura' else 'desc'
                        source = ((self.d.texts.get(target) or {}).get(self.locale) or {}).get(key, '')
                        out.append(self.render(source, target, depth + 1))
                    i += len(m.group(0))
                else:
                    m = re.match(r'@\w+', text[i:])
                    i += len(m.group(0)) if m else 1
                continue
            if c in '/*':
                m = re.match(r'[/*](\d+(?:\.\d+)?);(\d*)([a-zA-Z])(\d?)', text[i:])
                if m:
                    target = int(m.group(2)) if m.group(2) else spell
                    value = self.value(target, m.group(3), int(m.group(4) or 1)) or 0
                    value = value / float(m.group(1)) if c == '/' else value * float(m.group(1))
                    last_number = value
                    out.append(number(value, self.locale))
                    i += len(m.group(0))
                    continue
            if c in 'lLgG':
                end = text.find(';', i)
                if end > 0 and ':' in text[i + 1:end]:
                    forms = text[i + 1:end].split(':')
                    if c in 'gG':
                        out.append(forms[0])
                    else:
                        out.append(forms[0] if last_number is not None and abs(last_number) == 1 else forms[-1])
                    i = end + 1
                    continue
            if c == '<':
                end = text.find('>', i)
                i = end + 1 if end > 0 else i + 1
                out.append('X')
                continue
            m = re.match(r'(\d*)([a-zA-Z])(\d?)', text[i:])
            if m:
                letters = re.match(r'[A-Z]{2,}|[a-z]{2,}', text[i:]) if not m.group(1) else None
                if letters and letters.group(0).lower() in STAT_TOKENS:
                    out.append('0'); i += len(letters.group(0)); continue
                target = int(m.group(1)) if m.group(1) else spell
                rendered = self.token_text(target, m.group(2), int(m.group(3) or 0))
                value = self.value(target, m.group(2), int(m.group(3) or 0))
                if value is not None:
                    last_number = value
                out.append(rendered)
                i += len(m.group(0))
                continue
            out.append('$')
        return re.sub(r'[ \t]+\n', '\n', ''.join(out)).strip()

    @staticmethod
    def _match(text, start, opening, closing):
        depth = 0
        for index in range(start, len(text)):
            if text[index] == opening:
                depth += 1
            elif text[index] == closing:
                depth -= 1
                if depth == 0:
                    return index
        return len(text) - 1

    def _conditional(self, text, i):
        """Skips `cond[a]?cond[b][c]` from i; returns (next index, default branch)."""
        groups = []
        n = len(text)
        while i < n:
            while i < n and text[i] not in '[':
                if text[i] in ' .,\n' and not groups:
                    break
                i += 1
            if i >= n or text[i] != '[':
                break
            end = self._match(text, i, '[', ']')
            groups.append(text[i + 1:end])
            i = end + 1
            if i < n and text[i] == '?':
                i += 1
                continue
            if i < n and text[i] == '[':
                end = self._match(text, i, '[', ']')
                groups.append(text[i + 1:end])
                i = end + 1
            break
        return i, (groups[-1] if groups else '')

    def _evaluate(self, expression, spell, depth):
        def replace(m):
            if m.group(0).startswith('$?') or m.group(0).startswith('$@'):
                return '0'
            letters = m.group(3)
            if not m.group(2) and len(letters) > 1:
                return '0'  # character stats
            target = int(m.group(2)) if m.group(2) else spell
            value = self.value(target, letters, int(m.group(4) or 0))
            return repr(float(value or 0))
        cleaned = re.sub(r'\$(\?|@)?(\d*)([a-zA-Z]+?)(\d?)(?![a-zA-Z])', replace, expression)
        cleaned = re.sub(r'\b(cond|max|min|floor|ceil|abs)\b', lambda m: {'cond': '_cond', 'max': 'max', 'min': 'min', 'floor': 'math.floor', 'ceil': 'math.ceil', 'abs': 'abs'}[m.group(1)], cleaned)
        if re.search(r'[^0-9.+\-*/(), _a-z]', cleaned) or re.search(r'[a-z_]', re.sub(r'math\.floor|math\.ceil|max|min|abs|_cond', '', cleaned)):
            return None
        try:
            return float(eval(cleaned, {'__builtins__': {}}, {'math': math, 'max': max, 'min': min, 'abs': abs, '_cond': lambda c, a, b=0: a if c else b}))
        except Exception:
            return None


STAT_TOKENS = {'ap', 'rap', 'sp', 'sps', 'sph', 'pl', 'mws', 'mwb', 'mw', 'bh', 'hnd', 'int', 'sta', 'str', 'agi', 'spi', 'pbh', 'pbhd',
               'spfi', 'spfr', 'spn', 'sps', 'sph', 'spa', 'spsh', 'ows', 'owb', 'rwb', 'rws', 'mhp', 'mmp', 'hp', 'mp', 'versadmg', 'gte', 'lte'}
