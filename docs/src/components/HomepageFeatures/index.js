import React from 'react';
import clsx from 'clsx';
import styles from './styles.module.css';

const FeatureList = [
  {
    title: 'Student tracking (LVS)',
    description: (
      <>
        Track learners through OPP cycles, attendance, grades, and progress.
        Built for Dutch primary and secondary education, with the GEMMA
        onderwijs reference model and BRON / OSO transfer in mind.
      </>
    ),
  },
  {
    title: 'Course creation & certification (LMS)',
    description: (
      <>
        Author courses, set assignments, collect submissions, grade work,
        and issue verifiable certificates (EDCI / Open-Badges 3.0). IMS QTI
        3.0 native; cmi5 / xAPI content runtime.
      </>
    ),
  },
  {
    title: 'Compliance training & audit',
    description: (
      <>
        Bulk-enrol employees in mandatory AVG / BIO / NIS2 refreshers,
        capture signed attestations, detect expiring certificates, and
        export an audit pack with an immutable evidence log. All data as
        OpenRegister objects with a per-record audit trail.
      </>
    ),
  },
];

function Feature({ title, description }) {
  return (
    <div className={clsx('col col--4')}>
      <div className="text--center padding-horiz--md">
        <h3>{title}</h3>
        <p>{description}</p>
      </div>
    </div>
  );
}

export default function HomepageFeatures() {
  return (
    <section className={styles.features}>
      <div className="container">
        <div className="row">
          {FeatureList.map((props, idx) => (
            <Feature key={idx} {...props} />
          ))}
        </div>
      </div>
    </section>
  );
}
