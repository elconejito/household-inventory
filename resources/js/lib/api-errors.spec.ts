import { AxiosError } from 'axios';
import { describe, expect, it } from 'vitest';
import { parseApiErrors } from './api-errors';

describe('API form errors', () => {
    it('maps data pointers to fields and keeps non-field errors at form level', () => {
        const error = new AxiosError('Validation failed');
        Object.defineProperty(error, 'response', {
            value: {
                data: {
                    errors: [
                        {
                            detail: 'The email has already been taken.',
                            source: { pointer: '/data/email' },
                        },
                        {
                            detail: 'The request could not be completed.',
                        },
                    ],
                },
            },
        });

        expect(parseApiErrors(error)).toEqual({
            fields: { email: 'The email has already been taken.' },
            form: 'The request could not be completed.',
        });
    });
});
