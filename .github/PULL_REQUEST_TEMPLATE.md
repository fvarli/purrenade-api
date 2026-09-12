## Summary

<!-- What changed and why. Describe the change only. -->

## Milestone

<!-- e.g. M2 — Backend auth core. Link the relevant section of
     purrenade/docs/product/milestones.md -->

## Specification alignment

- [ ] Behavior matches the written Product/Game Specification
- [ ] No approved product behavior was changed silently
- [ ] No OPEN decision was implemented
- [ ] Any new decision is tagged APPROVED / PROPOSED / OPEN and recorded

## API contract

- [ ] No contract change, **or** `docs/api/openapi.draft.yaml` and the endpoint
      document were updated together with the implementation
- [ ] The frontend has been informed of any contract change
- [ ] Error responses use the documented envelope and stable codes

## Security checklist

- [ ] Authorization enforced server-side on every new or changed endpoint
- [ ] Explicit validation at the boundary
- [ ] Rate limiting considered and applied where appropriate
- [ ] No client-submitted score or progression value is trusted
- [ ] No sensitive data reaches logs
- [ ] Abuse cases tested, not only happy paths

## Data integrity

- [ ] Database constraints added alongside application validation
- [ ] Transactions used where integrity requires them
- [ ] Race conditions considered; concurrent access tested where relevant
- [ ] Idempotency handled where the operation must not double-apply
- [ ] Migration is reversible, or the reason it is not is stated
- [ ] Indexes justified by an actual query pattern

## Quality checklist

- [ ] Tests added/updated, including authorization, validation and edge cases
- [ ] Regression gates pass (`docs/testing/regression-gates.md`)
- [ ] Static analysis and formatting clean
- [ ] Documentation updated, including any affected ADR
- [ ] Diff reviewed for scope creep
