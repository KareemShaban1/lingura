/**
 * Script-aware text helpers mirroring the server's TextNormalizer: scripts written without spaces
 * (Chinese, Japanese, Thai…) are counted per character; everything else per word.
 */
const UNSPACED = /[\p{Script=Han}\p{Script=Hiragana}\p{Script=Katakana}\p{Script=Thai}\p{Script=Lao}\p{Script=Khmer}\p{Script=Myanmar}]/u;
const UNITS = /[\p{Script=Han}\p{Script=Hiragana}\p{Script=Katakana}\p{Script=Thai}\p{Script=Lao}\p{Script=Khmer}\p{Script=Myanmar}]|[\p{L}\p{M}\p{N}']+/gu;

export const isUnspaced = (text: string) => UNSPACED.test(text);

/** Words, or characters for unspaced scripts (how those languages measure length). */
export const countUnits = (text: string) => (text.trim().match(UNITS) ?? []).length;

/**
 * Splits a text into display tokens. Runs of unspaced script are segmented with the longest match
 * against `isWord` (dictionary keys from the server), so 你好 stays one tappable word.
 */
export function segmentTokens(text: string, isWord: (token: string) => boolean, max = 6): string[] {
  const out: string[] = [];
  for (const part of text.split(/([\p{L}\p{M}']+)/u)) {
    if (!part) continue;
    if (!isUnspaced(part)) {
      out.push(part);
      continue;
    }
    const chars = Array.from(part);
    let i = 0;
    while (i < chars.length) {
      let len = Math.min(max, chars.length - i);
      while (len > 1 && !isWord(chars.slice(i, i + len).join(""))) len--;
      out.push(chars.slice(i, i + len).join(""));
      i += len;
    }
  }
  return out;
}
