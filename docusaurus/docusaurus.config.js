// @ts-check
/** @type {import('@docusaurus/types').Config} */
const config = {
  title: 'Scholiq Documentation',
  tagline: 'Open-source education platform for Nextcloud — student tracking, course creation, eLearning, training management, and certification.',
  url: 'https://scholiq.app',
  baseUrl: '/',
  organizationName: 'ConductionNL',
  projectName: 'scholiq',
  trailingSlash: false,
  onBrokenLinks: 'throw',
  onBrokenMarkdownLinks: 'warn',
  i18n: { defaultLocale: 'en', locales: ['en', 'nl'] },
  presets: [[
    'classic',
    ({
      docs: {
        path: '../docs',
        sidebarPath: require.resolve('./sidebars.js'),
        editUrl: 'https://github.com/ConductionNL/scholiq/edit/development/',
        routeBasePath: 'docs',
      },
      blog: false,
      theme: { customCss: require.resolve('./src/css/custom.css') },
    }),
  ]],
  themeConfig: ({
    navbar: {
      title: 'Scholiq',
      logo: { alt: 'Scholiq Logo', src: 'img/logo.svg' },
      items: [
        { type: 'docSidebar', sidebarId: 'tutorialSidebar', position: 'left', label: 'Documentation' },
        { href: 'https://github.com/ConductionNL/scholiq', label: 'GitHub', position: 'right' },
      ],
    },
    footer: {
      style: 'dark',
      links: [
        { title: 'Docs', items: [
          { label: 'Introduction', to: '/docs/intro' },
          { label: 'Architecture', to: '/docs/ARCHITECTURE' },
          { label: 'Features', to: '/docs/FEATURES' },
          { label: 'Design References', to: '/docs/DESIGN-REFERENCES' },
        ]},
        { title: 'Community', items: [
          { label: 'GitHub', href: 'https://github.com/ConductionNL/scholiq' },
          { label: 'Conduction', href: 'https://conduction.nl' },
        ]},
      ],
      copyright: `Copyright © ${new Date().getFullYear()} Conduction. Built with Docusaurus. Licensed under EUPL-1.2.`,
    },
    prism: {
      // Inline themes — avoids `require('prism-react-renderer/themes/...')`
      // which breaks the Docusaurus 3.7 + webpack-5 build (ProgressPlugin
      // schema validation error). Mirrors openconnector/docusaurus.
      theme: {
        plain: { color: '#393A34', backgroundColor: '#f6f8fa' },
        styles: [],
      },
      darkTheme: {
        plain: { color: '#F8F8F2', backgroundColor: '#282A36' },
        styles: [],
      },
    },
  }),
};
module.exports = config;
