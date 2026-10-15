# Repo docs/tasks guidelines

This repository uses AGENTS.md files to convey per-directory instructions.

Guidelines for docs/tasks directory:

- Use the template in `docs\tasks\issue template.md` when creating a new task, or to guide what goes in each section.  That template includes 'Section usage note : ' under each title.  These should not be included, the are just to guide on what shoud be included in that section.  Sectins can be added, but all should appear, even if they get flagged as 'Not applicable'
- As tasks are completed they are moved to docs\tasks\completed_tasks.  If pointed to a file in docs\tasks then it might have been moved to docs\tasks\completed_tasks.  Only a human should move the file to completed, never an LLM agent.
- The naming of tasks start in the form `#XXX` where XXX is a number.  This is a cross-reference to an issue in GitHub
- When reviewing issues contained in files in this directory, ask any clarifying questions, including those on which you are curious about functional or design choices that strike you as unusual, in the `## Implementation questions and notes` section which is typically found at the end of the document, and can be added if not present.  They will be answered via file updates.
- For every new or materially revised task, complete the `## Design conformance` section from the issue template before implementation:
  - Treat a task as design-significant when it changes or depends materially on domain identity, persistence, data ownership, lifecycle, API or event contracts, authorization, cross-component workflow, or another architectural invariant.
  - Read `docs/design/agent-navigation-guide.md`, the design sources it routes to for the affected area, and linked predecessor task decisions. Record the applicable source, behavioural invariant, affected implementation boundary, planned evidence, and any explicitly agreed variance in the task document.
  - If implementation would conflict with an applicable design source, record the conflict in `## New implementation questions and notes` and obtain an explicit design decision. Do not silently update a design document to legitimise accidental implementation behaviour.
  - Convert objective core invariants into durable tests. Tests should assert the behaviour that distinguishes the design choices, not only incidental valid output.
  - Before marking a design-significant delivery phase or the task complete, reconcile the implemented behaviour and evidence against the applicable rows. Fix unexplained differences or record an explicitly agreed variance, then update affected design documents to reflect the agreed result.
  - For a task that is not design-significant, write `Not design-significant` with a brief reason. A detailed matrix is not required.
- For every new or materially revised task, complete the `## Legal terms and conditions conformance` section from the issue template before implementation:
  - The current terms document is set out in `docs\legal\terms\manifest.json` in `docs\legal\terms`
  - If an potential conflict with the current terms is observed then it MUST be recorded in `## New implementation questions and notes` and obtain an explicit decision. In most case the requirements will be changed to avoid a change
  - A change to terms may be justifed in rare cases, but this must always be done manualy as a discrete implementation step and then the date for acceptance considered in rollout scheduling
- If updating issues in this directory then update ONLY files in this directory unless otherwise directed.  That is part of desired workflow for issues
