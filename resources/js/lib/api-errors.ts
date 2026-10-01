import axios from 'axios';

type ApiError = {
    detail?: string;
    source?: {
        pointer?: string;
    };
};

type ApiErrorResponse = {
    errors?: ApiError[];
};

export type FormErrors = {
    fields: Record<string, string>;
    form: string;
};

export function parseApiErrors(error: unknown): FormErrors {
    if (!axios.isAxiosError<ApiErrorResponse>(error)) {
        return { fields: {}, form: 'Something went wrong. Please try again.' };
    }

    const errors = error.response?.data?.errors ?? [];
    const fields: Record<string, string> = {};
    const formMessages: string[] = [];

    for (const apiError of errors) {
        const fieldName = apiError.source?.pointer
            ?.replace(/^\/data\//, '')
            .replaceAll('~1', '/')
            .replaceAll('~0', '~');

        if (fieldName && !fields[fieldName]) {
            fields[fieldName] = apiError.detail ?? 'Please check this field.';
        } else if (apiError.detail) {
            formMessages.push(apiError.detail);
        }
    }

    if (formMessages.length === 0 && Object.keys(fields).length === 0) {
        formMessages.push('Something went wrong. Please try again.');
    }

    return {
        fields,
        form: formMessages.join(' '),
    };
}
