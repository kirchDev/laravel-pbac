export default {
  '*.{js,ts,mjs,cjs}': (filenames) => {
    const formattable = filenames.filter((f) => !f.endsWith('.d.ts'));
    const cmds = [
      `pnpm exec oxlint --fix --deny-warnings ${filenames.join(' ')}`
    ];
    if (formattable.length > 0) {
      cmds.push(`pnpm exec oxfmt ${formattable.join(' ')}`);
    }
    return cmds;
  },
  '*.{json,jsonc,yml,yaml,md}': (filenames) => {
    const validFiles = filenames.filter((f) => !f.includes('pnpm-lock.yaml'));
    return validFiles.length > 0
      ? `pnpm exec oxfmt ${validFiles.join(' ')}`
      : 'echo "No files to format"';
  },
  '{src,tests,config,database}/**/*.php': [
    './vendor/bin/pint',
    () => './vendor/bin/phpstan analyse --memory-limit=512M'
  ]
};
