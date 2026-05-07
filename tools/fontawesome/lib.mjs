import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
export const configPath = path.join(projectRoot, 'fontawesome.json');
export const outputDir = path.join(projectRoot, 'app/fontawesome');
export const linkPartialPath = path.join(projectRoot, 'app/twig/fontawesome_link.twig');

export const styles = ['solid', 'regular', 'brands'];

const styleClassMap = new Map([
  ['fa-solid', 'solid'],
  ['fa-regular', 'regular'],
  ['fa-brands', 'brands']
]);

const utilityClasses = new Set([
  'fa-2xs',
  'fa-xs',
  'fa-sm',
  'fa-lg',
  'fa-xl',
  'fa-2xl',
  'fa-fw',
  'fa-ul',
  'fa-li',
  'fa-border',
  'fa-pull-left',
  'fa-pull-right',
  'fa-beat',
  'fa-bounce',
  'fa-fade',
  'fa-beat-fade',
  'fa-flip',
  'fa-flip-horizontal',
  'fa-flip-vertical',
  'fa-shake',
  'fa-spin',
  'fa-spin-pulse',
  'fa-spin-reverse',
  'fa-rotate-90',
  'fa-rotate-180',
  'fa-rotate-270',
  'fa-inverse'
]);

export function readJson(filePath) {
  return JSON.parse(fs.readFileSync(filePath, 'utf8'));
}

export function iconKey(style, name) {
  return `${style}:${name}`;
}

export function parseIconKey(key) {
  const [style, name] = key.split(':');
  return { style, name };
}

export function normalizeConfig(config) {
  const normalized = new Map();

  for (const style of styles) {
    const icons = config[style] ?? [];
    if (!Array.isArray(icons)) {
      throw new Error(`fontawesome.json: "${style}" must be an array`);
    }

    normalized.set(style, [...new Set(icons)].sort());
  }

  const unknownStyles = Object.keys(config).filter((style) => !styles.includes(style));
  if (unknownStyles.length) {
    throw new Error(`fontawesome.json: unknown styles: ${unknownStyles.join(', ')}`);
  }

  return normalized;
}

export function configuredKeys(config = readJson(configPath)) {
  const normalized = normalizeConfig(config);
  return new Set(styles.flatMap((style) => normalized.get(style).map((name) => iconKey(style, name))));
}

export async function loadIconDefinitions() {
  const [solidPackage, regularPackage, brandsPackage] = await Promise.all([
    import('@fortawesome/free-solid-svg-icons'),
    import('@fortawesome/free-regular-svg-icons'),
    import('@fortawesome/free-brands-svg-icons')
  ]);

  return new Map([
    ['solid', definitionsByName(solidPackage)],
    ['regular', definitionsByName(regularPackage)],
    ['brands', definitionsByName(brandsPackage)]
  ]);
}

export function validateConfiguredIcons(config, definitions) {
  const errors = [];
  const normalized = normalizeConfig(config);

  for (const style of styles) {
    const styleDefinitions = definitions.get(style);
    for (const name of normalized.get(style)) {
      if (!styleDefinitions.has(name)) {
        errors.push(`${style}:${name}`);
      }
    }
  }

  if (errors.length) {
    throw new Error(`Unknown Font Awesome Free icons in fontawesome.json:\n${errors.map((key) => `  - ${key}`).join('\n')}`);
  }
}

export function scanUsedIconKeys() {
  const files = [
    ...listFiles(path.join(projectRoot, 'app/twig'), ['.twig']),
    ...listFiles(path.join(projectRoot, 'app/classes'), ['.php']),
    path.join(projectRoot, 'app/script.js')
  ].filter((file) => file !== linkPartialPath && fs.existsSync(file));

  const found = new Set();

  for (const file of files) {
    const source = fs.readFileSync(file, 'utf8');
    scanStyledLines(source, found);
    scanStyledClassStrings(source, found);
    scanImplicitSolidIconClasses(source, found);
  }

  return found;
}

function definitionsByName(moduleNamespace) {
  const definitions = new Map();

  for (const value of Object.values(moduleNamespace)) {
    if (value && typeof value === 'object' && typeof value.iconName === 'string' && Array.isArray(value.icon)) {
      definitions.set(value.iconName, value);

      const aliases = value.icon[2] ?? [];
      for (const alias of aliases) {
        if (typeof alias === 'string') {
          definitions.set(alias, value);
        }
      }
    }
  }

  return definitions;
}

function listFiles(dir, extensions) {
  if (!fs.existsSync(dir)) {
    return [];
  }

  const result = [];
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const fullPath = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      result.push(...listFiles(fullPath, extensions));
    } else if (extensions.includes(path.extname(entry.name))) {
      result.push(fullPath);
    }
  }

  return result;
}

function scanStyledLines(source, found) {
  for (const line of source.split('\n')) {
    const tokens = line.match(/\bfa-[a-z0-9-]+\b/g) ?? [];
    const styleTokens = tokens.filter((token) => styleClassMap.has(token));

    for (const styleToken of styleTokens) {
      const style = styleClassMap.get(styleToken);
      for (const token of tokens) {
        if (isIconClass(token)) {
          found.add(iconKey(style, token.slice(3)));
        }
      }
    }
  }
}

function scanStyledClassStrings(source, found) {
  const quotedStringPattern = /(['"`])((?:\\.|(?!\1)[\s\S])*?)\1/g;
  let match;

  while ((match = quotedStringPattern.exec(source))) {
    const value = match[2];
    const tokens = value.match(/\bfa-[a-z0-9-]+\b/g) ?? [];
    const styleToken = tokens.find((token) => styleClassMap.has(token));
    if (!styleToken) {
      continue;
    }

    const style = styleClassMap.get(styleToken);
    for (const token of tokens) {
      if (isIconClass(token)) {
        found.add(iconKey(style, token.slice(3)));
      }
    }
  }
}

function scanImplicitSolidIconClasses(source, found) {
  const implicitPatterns = [
    /\btoggleClass\(\s*['"`](fa-[a-z0-9-]+)['"`]/g,
    /\baddClass\(\s*['"`](fa-[a-z0-9-]+)['"`]/g,
    /\bremoveClass\(\s*['"`](fa-[a-z0-9-]+)['"`]/g,
    /\bicon_class\s*=\s*['"`](fa-[a-z0-9-]+)['"`]/g
  ];

  for (const pattern of implicitPatterns) {
    let match;
    while ((match = pattern.exec(source))) {
      const token = match[1];
      if (isIconClass(token)) {
        found.add(iconKey('solid', token.slice(3)));
      }
    }
  }
}

function isIconClass(token) {
  return token.startsWith('fa-') && !styleClassMap.has(token) && !utilityClasses.has(token);
}
