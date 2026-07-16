## ADDED Requirements

### Requirement: ItemBank exports its items as a QTI 3.0 package

The system MUST support exporting an `ItemBank` and its `Item`s as a QTI 3.0 package (a ZIP containing an
`imsmanifest.xml` and one `assessmentItem` XML per `Item`), completing the "Items use QTI 3.0 as canonical
form" requirement's import-only coverage into a round-trip. Because every `Item.qtiBody` already holds
verbatim, valid QTI 3.0 XML — written by both `QtiImportService` on import and `ItemAuthorView` on manual
authoring — the exporter MUST wrap the stored `qtiBody` directly rather than re-deriving it from
`interactionType`/`correctResponse`, so export fidelity is not limited by the pre-existing import-side
interaction-type parsing gap (that gap affects what `QtiImportService` can *extract into* `correctResponse`
on import; it does not affect what is already stored in `qtiBody` and therefore does not affect export). The
export MUST be usable independently of course-package export (e.g. an item author moving one bank between
Scholiq tenants) and MUST be the same code path `course-management`'s course export calls for embedded
assessment items, per this capability's ownership of `Item`/`ItemBank`.

#### Scenario: Exporting an ItemBank produces a valid QTI 3.0 package

- **GIVEN** an `ItemBank` containing `Item`s of mixed `interactionType` (some fully parsed on import, some
  with only raw `qtiBody` preserved)
- **WHEN** an authorised user exports the `ItemBank`
- **THEN** the system produces a ZIP with an `imsmanifest.xml` referencing one `assessmentItem` XML per
  `Item`, each containing that item's stored `qtiBody` verbatim

#### Scenario: Export fidelity is not limited by the import-side parsing gap

<!-- @e2e exclude Verifies a data-fidelity property (stored qtiBody is exported byte-for-byte regardless of
     interactionType) via PHPUnit comparing the exported XML to the stored qtiBody; no DOM surface for XML
     byte-equality. -->

- **GIVEN** an `Item` whose `interactionType` was imported with the pre-existing "raw qtiBody preserved,
  correctResponse pending a future parser extension" degradation (an interaction type beyond `choice`/
  `extendedText`)
- **WHEN** that `Item`'s `ItemBank` is exported
- **THEN** the exported `assessmentItem` XML matches the stored `qtiBody` exactly, unaffected by the fact
  that `correctResponse` was not fully parsed on import
