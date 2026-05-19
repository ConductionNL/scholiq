/**
 * Scholiq landing page.
 *
 * Composes the brand <DetailHero> + <WidgetShelf> from
 * @conduction/docusaurus-preset/components, mirroring the openregister
 * landing at openregister.conduction.nl and the pipelinq landing.
 *
 * Written as .js (not .mdx) because the docs site has the docs plugin
 * pointed at `path: './'`, and an MDX file in src/pages/ trips the
 * MDX-ESM parser even with the docs plugin's `src/**` exclude,likely
 * a quirk of how mdx-loader's micromark stack reuses parser state
 * across files in this Docusaurus 3.10 + this preset combination.
 * Authoring the page in JSX keeps the same component composition.
 *
 * Scholiq has no AppMock variant in @conduction/docusaurus-preset yet,
 * so the right-column illustration is a small token-built mock built
 * inline here (same approach the WidgetShelf panels use). Swap in
 * `<AppMock app="scholiq" />` once the preset ships a variant.
 */

import React from 'react';
import Layout from '@theme/Layout';
import {
  DetailHero,
  WidgetShelf,
} from '@conduction/docusaurus-preset/components';

/* Mortarboard / graduation-cap glyph,lifted from scholiq/img/app.svg. */
const SCHOLIQ_ICON = (
  <svg viewBox="0 0 24 24">
    <path d="M12 3L1 9l11 6 9-4.91V17h2V9L12 3zM5 13.18v4L12 21l7-3.82v-4L12 17l-7-3.82z" />
  </svg>
);

const TAGLINE = (
  <>
    The learning record and learning-management layer for{' '}
    <span className="next-blue">Nextcloud</span>: courses, enrolment,
    assignments, attendance, grading, certification, and compliance
    training, all manifest-first on OpenRegister.
  </>
);

/* ---- Right-column illustration: an abstract Scholiq course-board ---- */

function ScholiqMock() {
  const courses = [
    { tone: 'var(--c-forest-300)', w: '78%' },
    { tone: 'var(--c-lavender-300)', w: '64%' },
    { tone: 'var(--c-mint-300)', w: '52%' },
    { tone: 'var(--c-terracotta-300)', w: '40%' },
  ];
  return (
    <div
      style={{
        background: 'var(--c-cream, #fff)',
        borderRadius: 8,
        boxShadow: '0 12px 40px rgba(0, 0, 0, 0.18)',
        padding: 16,
        width: '100%',
        maxWidth: 420,
        display: 'flex',
        flexDirection: 'column',
        gap: 12,
      }}
    >
      {/* title bar */}
      <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
        <span
          style={{
            width: 16,
            height: 18,
            clipPath: 'var(--hex-pointy-top)',
            background: 'var(--c-orange-knvb)',
            flexShrink: 0,
          }}
        />
        <div
          style={{
            height: 6,
            width: '40%',
            background: 'var(--c-cobalt-700)',
            borderRadius: 1,
          }}
        />
        <div
          style={{
            marginLeft: 'auto',
            fontFamily: 'var(--conduction-typography-font-family-code)',
            fontSize: 11,
            fontWeight: 700,
            color: 'var(--c-mint-500)',
          }}
        >
          12 active
        </div>
      </div>
      {/* course rows */}
      {courses.map((c, i) => (
        <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <span
            style={{
              width: 22,
              height: 22,
              borderRadius: 3,
              background: c.tone,
              flexShrink: 0,
            }}
          />
          <div
            style={{
              flex: 1,
              display: 'flex',
              flexDirection: 'column',
              gap: 4,
            }}
          >
            <div
              style={{
                height: 5,
                width: '70%',
                background: 'var(--c-cobalt-700)',
                borderRadius: 1,
              }}
            />
            <div
              style={{
                height: 6,
                background: 'var(--c-cobalt-50)',
                borderRadius: 1,
                overflow: 'hidden',
              }}
            >
              <div
                style={{
                  height: '100%',
                  width: c.w,
                  background: 'var(--c-cobalt-300)',
                  borderRadius: 1,
                }}
              />
            </div>
          </div>
          <div
            style={{
              fontFamily: 'var(--conduction-typography-font-family-code)',
              fontSize: 10,
              fontWeight: 700,
              color: 'var(--c-cobalt-700)',
              minWidth: 28,
              textAlign: 'right',
            }}
          >
            {c.w}
          </div>
        </div>
      ))}
    </div>
  );
}

/* ---- WidgetShelf panels ---- */

function MyCoursesPanel() {
  const tones = [
    'var(--c-forest-300)',
    'var(--c-lavender-300)',
    'var(--c-mint-300)',
    'var(--c-terracotta-300)',
    'var(--c-forest-300)',
  ];
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
      {tones.map((tone, i) => (
        <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          <span
            style={{
              width: 18,
              height: 18,
              borderRadius: 2,
              background: tone,
              flexShrink: 0,
            }}
          />
          <div
            style={{
              flex: 1,
              display: 'flex',
              flexDirection: 'column',
              gap: 3,
            }}
          >
            <div
              style={{
                height: 4,
                width: '70%',
                background: 'var(--c-cobalt-700)',
                borderRadius: 1,
              }}
            />
            <div
              style={{
                height: 3,
                width: '50%',
                background: 'var(--c-cobalt-200)',
                borderRadius: 1,
              }}
            />
          </div>
          <div
            style={{
              height: 3,
              width: 22,
              background: 'var(--c-cobalt-100)',
              borderRadius: 1,
            }}
          />
        </div>
      ))}
    </div>
  );
}

function PendingGradingPanel() {
  const rows = [
    { tone: 'var(--c-orange-knvb)', label: 'ESSAY', w: '85%' },
    { tone: 'var(--c-cobalt-300)', label: 'QUIZ', w: '70%' },
    { tone: 'var(--c-lavender-300)', label: 'ESSAY', w: '60%' },
    { tone: 'var(--c-cobalt-300)', label: 'LAB', w: '50%' },
    { tone: 'var(--c-mint-500)', label: 'QUIZ', w: '40%' },
  ];
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
      {rows.map((row, i) => (
        <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          <span
            style={{
              width: 14,
              height: 16,
              clipPath: 'var(--hex-pointy-top)',
              background: row.tone,
              flexShrink: 0,
            }}
          />
          <div
            style={{
              fontFamily: 'var(--conduction-typography-font-family-code)',
              fontSize: 8,
              fontWeight: 700,
              letterSpacing: '0.05em',
              color: 'var(--c-cobalt-700)',
              minWidth: 42,
            }}
          >
            {row.label}
          </div>
          <div
            style={{
              flex: 1,
              height: 6,
              background: 'var(--c-cobalt-50)',
              borderRadius: 1,
              overflow: 'hidden',
            }}
          >
            <div
              style={{
                height: '100%',
                width: row.w,
                background: 'var(--c-cobalt-300)',
                borderRadius: 1,
              }}
            />
          </div>
        </div>
      ))}
    </div>
  );
}

function RecentCertificationsPanel() {
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
      <div style={{ display: 'flex', alignItems: 'baseline', gap: 6 }}>
        <div
          style={{
            fontFamily: 'var(--conduction-typography-font-family-code)',
            fontSize: 26,
            fontWeight: 700,
            color: 'var(--c-cobalt-900)',
          }}
        >
          318
        </div>
        <div
          style={{
            fontFamily: 'var(--conduction-typography-font-family-code)',
            fontSize: 11,
            fontWeight: 600,
            color: 'var(--c-mint-500)',
          }}
        >
          +9%
        </div>
      </div>
      <svg
        viewBox="0 0 200 60"
        preserveAspectRatio="none"
        style={{ width: '100%', height: 50 }}
      >
        <path
          d="M 0 50 L 28 44 L 56 46 L 84 30 L 112 34 L 140 20 L 168 24 L 200 10"
          stroke="var(--c-blue-cobalt)"
          strokeWidth="2"
          fill="none"
        />
        <path
          d="M 0 50 L 28 44 L 56 46 L 84 30 L 112 34 L 140 20 L 168 24 L 200 10 L 200 60 L 0 60 Z"
          fill="var(--c-cobalt-100)"
        />
        <circle cx="200" cy="10" r="3" fill="var(--c-orange-knvb)" />
      </svg>
    </div>
  );
}

const WIDGETS = [
  {
    title: 'My courses',
    desc: 'Every course the logged-in learner is enrolled in, with progress bars per course. Click through to the lesson where you left off.',
    panel: <MyCoursesPanel />,
  },
  {
    title: 'Pending grading',
    desc: 'Submitted assignments waiting on a grade, oldest first, tagged by type. Open the submission, grade it, the learning record updates.',
    panel: <PendingGradingPanel />,
  },
  {
    title: 'Recent certifications',
    desc: 'Certificates issued this period, trended over time. Compliance-training renewals fall out of the same register, no spreadsheet at audit time.',
    panel: <RecentCertificationsPanel />,
  },
];

export default function Home() {
  return (
    <Layout
      title="Scholiq, learning and course management for Nextcloud"
      description="The learning record and learning-management layer for Nextcloud: courses, enrolment, assignments, attendance, grading, certification, and compliance training, manifest-first on OpenRegister."
    >
      <main className="marketing-page">
        <DetailHero
          background="cobalt"
          appId="scholiq"
          status={{ label: 'Beta', color: 'var(--c-orange-knvb)' }}
          locales="EN"
          title="Scholiq"
          tagline={TAGLINE}
          primaryCta={{
            label: 'Install from app store',
            href: 'https://apps.nextcloud.com/apps/scholiq',
            tone: 'orange',
          }}
          secondaryCta={{ label: 'Read the docs', href: '/docs/intro' }}
          tertiaryCta={{
            label: 'View on GitHub',
            href: 'https://github.com/ConductionNL/scholiq',
          }}
          iconColor="var(--c-orange-knvb)"
          icon={SCHOLIQ_ICON}
          illustration={<ScholiqMock />}
        />

        <WidgetShelf
          eyebrow="Widgets we ship"
          title="The classroom on every Nextcloud dashboard."
          lede="Install Scholiq and these widgets show up on every learner's and instructor's home screen. Your courses first, what's waiting on a grade next, certifications below."
          widgets={WIDGETS}
        />
      </main>
    </Layout>
  );
}
