import {
  configPath,
  configuredKeys,
  iconKey,
  loadIconDefinitions,
  parseIconKey,
  readJson,
  scanUsedIconKeys,
  validateConfiguredIcons
} from './lib.mjs';

const config = readJson(configPath);
const definitions = await loadIconDefinitions();
validateConfiguredIcons(config, definitions);

const configured = configuredKeys(config);
const used = scanUsedIconKeys();

const missing = [...used].filter((key) => !configured.has(key)).sort();
const unused = [...configured].filter((key) => !used.has(key)).sort();

if (missing.length) {
  console.error('Font Awesome icons used in code but missing from fontawesome.json:');
  for (const key of missing) {
    console.error(`  - ${key}`);
  }
}

if (unused.length) {
  console.warn('Font Awesome icons configured but not found in code:');
  for (const key of unused) {
    console.warn(`  - ${key}`);
  }
}

if (missing.length) {
  process.exit(1);
}

const usedByStyle = [...used]
  .map(parseIconKey)
  .reduce((result, { style, name }) => {
    result[style] ??= [];
    result[style].push(name);
    return result;
  }, {});

console.log(`Font Awesome icon config is in sync (${used.size} used icons).`);
for (const style of Object.keys(usedByStyle).sort()) {
  console.log(`  ${style}: ${usedByStyle[style].sort().join(', ')}`);
}
