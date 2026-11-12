import js from '@eslint/js';
import globals from 'globals';
import tseslint from 'typescript-eslint';
import reactHooks from 'eslint-plugin-react-hooks';
import reactRefresh from 'eslint-plugin-react-refresh';
import jsxA11y from 'eslint-plugin-jsx-a11y';

// Local rule: no raw hex or rgb()/rgba() color literals anywhere except the generated token file (CLAUDE.md §2.3).
const HEX = /#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})\b|\brgba?\(/;
const noRawHex = {
  meta: {
    type: 'problem',
    docs: { description: 'Disallow raw hex colors outside src/design/tokens.generated.ts' },
    schema: [],
    messages: { raw: 'Raw hex color "{{value}}" found. Use a design token instead.' },
  },
  create(context) {
    const check = (node, text) => {
      const m = HEX.exec(text);
      if (m) context.report({ node, messageId: 'raw', data: { value: m[0] } });
    };
    return {
      Literal(node) {
        if (typeof node.value === 'string') check(node, node.value);
      },
      TemplateElement(node) {
        check(node, node.value.raw);
      },
    };
  },
};

export default tseslint.config(
  { ignores: ['dist', 'node_modules', 'playwright-report', 'test-results', 'coverage'] },
  {
    files: ['**/*.{ts,tsx}'],
    extends: [js.configs.recommended, ...tseslint.configs.recommendedTypeChecked, jsxA11y.flatConfigs.recommended],
    languageOptions: {
      ecmaVersion: 2022,
      globals: { ...globals.browser, ...globals.node },
      parserOptions: {
        projectService: true,
        tsconfigRootDir: import.meta.dirname,
      },
    },
    plugins: {
      'react-hooks': reactHooks,
      'react-refresh': reactRefresh,
      orbin: { rules: { 'no-raw-hex': noRawHex } },
    },
    rules: {
      ...reactHooks.configs.recommended.rules,
      'react-refresh/only-export-components': ['warn', { allowConstantExport: true }],
      'orbin/no-raw-hex': 'error',
      '@typescript-eslint/no-explicit-any': 'error',
      '@typescript-eslint/no-misused-promises': ['error', { checksVoidReturn: { attributes: false } }],
      '@typescript-eslint/no-floating-promises': 'error',
      '@typescript-eslint/consistent-type-imports': 'error',
      '@typescript-eslint/no-unused-vars': ['error', { varsIgnorePattern: '^_', argsIgnorePattern: '^_', ignoreRestSiblings: true }],
      // react-hook-form's watch() is intentional in forms; the React Compiler advisory is informational only.
      'react-hooks/incompatible-library': 'off',
    },
  },
  {
    // The design-system barrel exports variant helpers (cva) next to components by design.
    files: ['src/design/components/**/*.tsx', 'src/app/router.tsx'],
    rules: { 'react-refresh/only-export-components': 'off' },
  },
  {
    // Only the generated token file may hold color literals.
    files: ['src/design/tokens.generated.ts'],
    rules: { 'orbin/no-raw-hex': 'off' },
  },
  {
    files: ['**/*.test.{ts,tsx}', 'src/test/**'],
    rules: { '@typescript-eslint/unbound-method': 'off' },
  },
);
